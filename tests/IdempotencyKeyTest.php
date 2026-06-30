<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Idempotency\IdempotencyKey;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(IdempotencyKey::class)]
final class IdempotencyKeyTest
{
    public function acceptsValidKey(): void
    {
        $key = new IdempotencyKey('abc-123.456_XYZ');

        Assert::same($key->value, 'abc-123.456_XYZ');
    }

    public function acceptsSingleChar(): void
    {
        $key = new IdempotencyKey('a');

        Assert::same($key->value, 'a');
    }

    public function acceptsMaxLength(): void
    {
        $value = str_repeat('a', 255);
        $key = new IdempotencyKey($value);

        Assert::same($key->value, $value);
    }

    public function rejectsEmptyString(): void
    {
        try {
            new IdempotencyKey('');
            Assert::fail('Expected \InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('between 1 and 255');
        }
    }

    public function rejectsTooLong(): void
    {
        try {
            new IdempotencyKey(str_repeat('a', 256));
            Assert::fail('Expected \InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('between 1 and 255');
        }
    }

    public function rejectsInvalidCharacters(): void
    {
        try {
            new IdempotencyKey('key with spaces');
            Assert::fail('Expected \InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('invalid characters');
        }
    }

    public static function invalidKeyProvider(): iterable
    {
        yield 'contains @' => ['key@value'];
        yield 'contains !' => ['key!value'];
        yield 'contains /' => ['key/value'];
        yield 'contains :' => ['key:value'];
    }

    #[DataProvider('invalidKeyProvider')]
    public function rejectsInvalidChars(string $value): void
    {
        try {
            new IdempotencyKey($value);
            Assert::fail('Expected \InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            Assert::true(true);
        }
    }

    public function equalsReturnsTrueForSameKey(): void
    {
        $a = new IdempotencyKey('key-1');
        $b = new IdempotencyKey('key-1');

        Assert::true($a->equals($b));
    }

    public function equalsReturnsFalseForDifferentKey(): void
    {
        $a = new IdempotencyKey('key-1');
        $b = new IdempotencyKey('key-2');

        Assert::false($a->equals($b));
    }

    #[Property(runs: 300)]
    public function validKeyPreservesValueAndEqualsItself(string $value): void
    {
        $key = new IdempotencyKey($value);

        Assert::same($key->value, $value);
        Assert::true($key->equals($key));
    }

    /** @return array<string, ArbitraryInterface> */
    private function validKeyPreservesValueAndEqualsItselfGenerators(): array
    {
        return ['value' => self::keyGenerator()];
    }

    #[Property(runs: 300)]
    public function equalsReflectsValueEquality(string $a, string $b): void
    {
        Assert::same((new IdempotencyKey($a))->equals(new IdempotencyKey($b)), $a === $b);
    }

    /** @return array<string, ArbitraryInterface> */
    private function equalsReflectsValueEqualityGenerators(): array
    {
        return [
            'a' => self::keyGenerator(),
            'b' => self::keyGenerator(),
        ];
    }

    /**
     * Generates valid idempotency keys: 1-100 chars drawn from the allowed
     * `[A-Za-z0-9._-]` alphabet, so they always pass construction validation.
     */
    private static function keyGenerator(): ArbitraryInterface
    {
        return Gen::map(
            Gen::nonEmptyArrayOf(Gen::intBetween(0, 64)),
            static fn(array $codes): string => \implode('', \array_map(
                static fn(int $code): string => 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789._-'[$code],
                $codes,
            )),
        );
    }
}
