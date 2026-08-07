<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Idempotency\IdempotencyKey;
use Rasuvaeff\Yii3Idempotency\IdempotencyScope;
use Rasuvaeff\Yii3Idempotency\IdempotencyScopeResolver;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(IdempotencyScope::class)]
final class IdempotencyScopeTest
{
    public function acceptsPlainName(): void
    {
        Assert::same((new IdempotencyScope('orders'))->name, 'orders');
    }

    public function acceptsRequestTargetShapedName(): void
    {
        Assert::same((new IdempotencyScope('POST /api/orders'))->name, 'POST /api/orders');
    }

    public function acceptsNonAsciiName(): void
    {
        Assert::same((new IdempotencyScope('POST /api/заказы'))->name, 'POST /api/заказы');
    }

    public function acceptsMaxLength(): void
    {
        $name = str_repeat('a', 1024);

        Assert::same((new IdempotencyScope($name))->name, $name);
    }

    #[DataProvider('invalidNameProvider')]
    public function rejectsInvalidName(string $name): void
    {
        try {
            new IdempotencyScope($name);
            Assert::fail('Expected \InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            Assert::true(true);
        }
    }

    public static function invalidNameProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'too long' => [str_repeat('a', 1025)];
        yield 'null byte' => ["orders\0"];
        yield 'newline' => ["orders\n"];
        yield 'tab' => ["orders\tv2"];
        yield 'delete' => ["orders\x7F"];
    }

    public function isItsOwnResolver(): void
    {
        $scope = new IdempotencyScope('orders');

        Assert::instanceOf($scope, IdempotencyScopeResolver::class);
        Assert::same($scope->resolve(new FakeRequest()), $scope);
    }

    public function equalsComparesNames(): void
    {
        Assert::true((new IdempotencyScope('orders'))->equals(new IdempotencyScope('orders')));
        Assert::false((new IdempotencyScope('orders'))->equals(new IdempotencyScope('payments')));
    }

    public function applyProducesASha256Key(): void
    {
        $scoped = (new IdempotencyScope('orders'))->apply(new IdempotencyKey('key-1'));

        Assert::same($scoped->value, hash('sha256', "orders\0key-1"));
    }

    public function applyIsDeterministic(): void
    {
        $scope = new IdempotencyScope('orders');

        Assert::true($scope->apply(new IdempotencyKey('key-1'))->equals(
            $scope->apply(new IdempotencyKey('key-1')),
        ));
    }

    public function differentScopesProduceDifferentKeys(): void
    {
        $key = new IdempotencyKey('key-1');

        Assert::false(
            (new IdempotencyScope('orders'))->apply($key)->equals(
                (new IdempotencyScope('payments'))->apply($key),
            ),
        );
    }

    public function applyKeepsAMaximumLengthKeyValid(): void
    {
        $scoped = (new IdempotencyScope('orders'))->apply(new IdempotencyKey(str_repeat('a', 255)));

        Assert::same(strlen($scoped->value), 64);
    }

    #[Property(runs: 300)]
    public function scopedKeysAreAlwaysWithinTheKeyFormat(string $scopeName, string $keyValue): void
    {
        $scoped = (new IdempotencyScope($scopeName))->apply(new IdempotencyKey($keyValue));

        Assert::same(strlen($scoped->value), 64);
        Assert::true(preg_match('/^[a-f0-9]+\z/', $scoped->value) === 1);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function scopedKeysAreAlwaysWithinTheKeyFormatGenerators(): array
    {
        return [
            'scopeName' => self::scopeNameGenerator(),
            'keyValue' => self::keyValueGenerator(),
        ];
    }

    #[Property(runs: 300)]
    public function distinctScopeKeyPairsDoNotCollide(
        string $scopeA,
        string $scopeB,
        string $keyA,
        string $keyB,
    ): void {
        $applied = static fn(string $scope, string $key): string
            => (new IdempotencyScope($scope))->apply(new IdempotencyKey($key))->value;

        $same = $scopeA === $scopeB && $keyA === $keyB;

        Assert::same($applied($scopeA, $keyA) === $applied($scopeB, $keyB), $same);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function distinctScopeKeyPairsDoNotCollideGenerators(): array
    {
        return [
            'scopeA' => Gen::oneOf('orders', 'payments', 'POST /api/orders', 'PUT /api/orders'),
            'scopeB' => Gen::oneOf('orders', 'payments', 'POST /api/orders', 'PUT /api/orders'),
            'keyA' => Gen::oneOf('key-1', 'key-2', 'a', str_repeat('z', 255)),
            'keyB' => Gen::oneOf('key-1', 'key-2', 'a', str_repeat('z', 255)),
        ];
    }

    /**
     * Printable ASCII, never empty — prefixing rather than filtering keeps every
     * run productive.
     */
    private static function scopeNameGenerator(): ArbitraryInterface
    {
        return Gen::map(
            Gen::stringAscii(),
            static fn(string $suffix): string => 's' . $suffix,
        );
    }

    /**
     * Any length the key format allows, built out of the whole legal alphabet.
     */
    private static function keyValueGenerator(): ArbitraryInterface
    {
        return Gen::map(
            Gen::intBetween(1, 255),
            static fn(int $length): string => substr(str_repeat('aZ9._-', 43), 0, $length),
        );
    }
}
