<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Idempotency\IdempotencyKeyExtractor;
use Rasuvaeff\Yii3Idempotency\MissingKeyException;
use Rasuvaeff\Yii3Idempotency\PayloadIdempotencyKeyExtractor;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(PayloadIdempotencyKeyExtractor::class)]
final class PayloadIdempotencyKeyExtractorTest
{
    public function implementsExtractorInterface(): void
    {
        Assert::instanceOf(new PayloadIdempotencyKeyExtractor('id'), IdempotencyKeyExtractor::class);
    }

    public function readsATopLevelField(): void
    {
        $extractor = new PayloadIdempotencyKeyExtractor('requestId');

        $key = $extractor->extract(new FakeRequest(parsedBody: ['requestId' => 'req-1']));

        Assert::same($key?->value, 'req-1');
    }

    public function readsANestedFieldByDotPath(): void
    {
        $extractor = new PayloadIdempotencyKeyExtractor('command.order.id');

        $key = $extractor->extract(new FakeRequest(
            parsedBody: ['command' => ['order' => ['id' => 'order-42']]],
        ));

        Assert::same($key?->value, 'order-42');
    }

    public function castsAnIntegerValueToAKey(): void
    {
        $extractor = new PayloadIdempotencyKeyExtractor('orderId');

        $key = $extractor->extract(new FakeRequest(parsedBody: ['orderId' => 42]));

        Assert::same($key?->value, '42');
    }

    #[DataProvider('unresolvableBodyProvider')]
    public function throwsWhenTheKeyIsRequiredAndUnresolvable(array|object|null $parsedBody): void
    {
        $extractor = new PayloadIdempotencyKeyExtractor('command.orderId');

        try {
            $extractor->extract(new FakeRequest(parsedBody: $parsedBody));
            Assert::fail('Expected MissingKeyException');
        } catch (MissingKeyException $e) {
            Assert::string($e->getMessage())->contains('command.orderId');
        }
    }

    #[DataProvider('unresolvableBodyProvider')]
    public function returnsNullWhenTheKeyIsOptionalAndUnresolvable(array|object|null $parsedBody): void
    {
        $extractor = new PayloadIdempotencyKeyExtractor('command.orderId', required: false);

        Assert::null($extractor->extract(new FakeRequest(parsedBody: $parsedBody)));
    }

    public static function unresolvableBodyProvider(): iterable
    {
        yield 'no parsed body' => [null];
        yield 'object body' => [new \stdClass()];
        yield 'empty array' => [[]];
        yield 'first segment missing' => [['other' => ['orderId' => 'x']]];
        yield 'last segment missing' => [['command' => ['other' => 'x']]];
        yield 'intermediate value is a scalar' => [['command' => 'not-an-array']];
        yield 'value is null' => [['command' => ['orderId' => null]]];
        yield 'value is a float' => [['command' => ['orderId' => 1.5]]];
        yield 'value is a bool' => [['command' => ['orderId' => true]]];
        yield 'value is an array' => [['command' => ['orderId' => ['nested']]]];
    }

    public function propagatesTheKeyFormatError(): void
    {
        $extractor = new PayloadIdempotencyKeyExtractor('id');

        try {
            $extractor->extract(new FakeRequest(parsedBody: ['id' => 'not a valid key']));
            Assert::fail('Expected \InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            Assert::true(true);
        }
    }

    #[DataProvider('invalidPathProvider')]
    public function rejectsAnInvalidPath(string $dotPath): void
    {
        try {
            new PayloadIdempotencyKeyExtractor($dotPath);
            Assert::fail('Expected \InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('segments must not be empty');
        }
    }

    public static function invalidPathProvider(): iterable
    {
        yield 'empty path' => [''];
        yield 'leading dot' => ['.id'];
        yield 'trailing dot' => ['id.'];
        yield 'double dot' => ['command..id'];
    }

    /**
     * Whatever nesting depth a path describes, a payload built along that path
     * resolves back to the value that was put there.
     */
    #[Property(runs: 300)]
    public function resolvesAnyPayloadBuiltAlongThePath(int $depth, string $keyValue): void
    {
        $segments = array_map(static fn(int $i): string => 'level' . $i, range(1, $depth));

        $payload = $keyValue;

        foreach (array_reverse($segments) as $segment) {
            $payload = [$segment => $payload];
        }

        $extractor = new PayloadIdempotencyKeyExtractor(implode('.', $segments));

        Assert::same($extractor->extract(new FakeRequest(parsedBody: $payload))?->value, $keyValue);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function resolvesAnyPayloadBuiltAlongThePathGenerators(): array
    {
        return [
            'depth' => Gen::intBetween(1, 6),
            'keyValue' => Gen::oneOf('key-1', 'a', 'ORDER.42_x', str_repeat('k', 255)),
        ];
    }
}
