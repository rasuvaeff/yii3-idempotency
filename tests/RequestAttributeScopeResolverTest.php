<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Idempotency\IdempotencyKey;
use Rasuvaeff\Yii3Idempotency\IdempotencyScope;
use Rasuvaeff\Yii3Idempotency\IdempotencyScopeResolver;
use Rasuvaeff\Yii3Idempotency\RequestAttributeScopeResolver;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(RequestAttributeScopeResolver::class)]
final class RequestAttributeScopeResolverTest
{
    public function implementsResolver(): void
    {
        Assert::instanceOf(new RequestAttributeScopeResolver(), IdempotencyScopeResolver::class);
    }

    public function readsTheConfiguredAttribute(): void
    {
        $resolver = new RequestAttributeScopeResolver(attribute: 'principal');

        $scope = $resolver->resolve(new FakeRequest(attributes: ['principal' => 'alice']));

        Assert::same($scope->name, 'caller:alice');
    }

    public function fallsBackToAnonymousWhenTheAttributeIsAbsent(): void
    {
        Assert::same((new RequestAttributeScopeResolver())->resolve(new FakeRequest())->name, 'caller:anonymous');
    }

    public function anonymousNameIsConfigurable(): void
    {
        $resolver = new RequestAttributeScopeResolver(anonymous: 'guest');

        Assert::same($resolver->resolve(new FakeRequest())->name, 'caller:guest');
    }

    /**
     * @param string|int|\Stringable|null $value
     */
    #[DataProvider('identityProvider')]
    public function acceptsScalarAndStringableIdentities(mixed $value, string $expected): void
    {
        $resolver = new RequestAttributeScopeResolver();

        Assert::same($resolver->resolve(new FakeRequest(attributes: ['user' => $value]))->name, $expected);
    }

    public static function identityProvider(): iterable
    {
        yield 'string' => ['alice', 'caller:alice'];
        yield 'int' => [42, 'caller:42'];
        yield 'zero' => [0, 'caller:0'];
        yield 'empty string falls back' => ['', 'caller:anonymous'];
        yield 'null falls back' => [null, 'caller:anonymous'];
        yield 'stringable' => [new StringableIdentity('bob'), 'caller:bob'];
        yield 'empty stringable falls back' => [new StringableIdentity(''), 'caller:anonymous'];
    }

    public function mapsAnObjectThroughTheIdentityClosure(): void
    {
        $resolver = new RequestAttributeScopeResolver(
            identity: static fn(mixed $user): ?string => $user instanceof \stdClass && isset($user->id)
                ? (string) $user->id
                : null,
        );

        $user = new \stdClass();
        $user->id = 7;

        Assert::same($resolver->resolve(new FakeRequest(attributes: ['user' => $user]))->name, 'caller:7');
    }

    public function rejectsAnIdentityItCannotStringify(): void
    {
        try {
            (new RequestAttributeScopeResolver())->resolve(new FakeRequest(attributes: ['user' => new \stdClass()]));
            Assert::fail('Expected \InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('must hold a string');
        }
    }

    public function rejectsAnEmptyAttributeName(): void
    {
        try {
            new RequestAttributeScopeResolver(attribute: '');
            Assert::fail('Expected \InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            Assert::true(actual: true);
        }
    }

    public function rejectsAnEmptyAnonymousName(): void
    {
        try {
            new RequestAttributeScopeResolver(anonymous: '');
            Assert::fail('Expected \InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            Assert::true(actual: true);
        }
    }

    /**
     * The security property: two distinct callers can never land on the same
     * storage key for the same client-supplied key, and one caller always does.
     */
    #[Property(runs: 300, timeoutMs: 2000)]
    public function distinctCallersNeverCollide(string $left, string $right, string $key): void
    {
        Classify::cover($left === $right, 'same caller', 5.0);
        Classify::cover($left !== $right, 'distinct callers', 40.0);

        $resolver = new RequestAttributeScopeResolver();
        $clientKey = new IdempotencyKey($key);

        $leftKey = $resolver->resolve(new FakeRequest(attributes: ['user' => $left]))->apply($clientKey);
        $rightKey = $resolver->resolve(new FakeRequest(attributes: ['user' => $right]))->apply($clientKey);

        Assert::same($leftKey->equals($rightKey), $left === $right);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function distinctCallersNeverCollideGenerators(): array
    {
        // A tiny alphabet on purpose: drawn from the whole string space two
        // callers are never equal, and the "same caller" branch never runs.
        $caller = Gen::stringFrom('ab', 1, 3);

        return [
            'left' => $caller,
            'right' => $caller,
            'key' => Gen::stringFrom('abcdef0123456789-._', 1, 40),
        ];
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function distinctCallersNeverCollideExamples(): iterable
    {
        yield 'same caller replays' => ['alice', 'alice', 'order-123'];
        yield 'caller vs anonymous fallback' => ['anonymous', 'a', 'order-123'];
        // The scenario from the report: a guessable key and a guessable payload.
        yield 'victim and attacker' => ['victim', 'attacker', 'order-123'];
    }

    /**
     * The `caller:` marker keeps a caller namespace from ever colliding with an
     * endpoint namespace carrying the same text.
     */
    public function isNamespacedAgainstOtherScopeDimensions(): void
    {
        $caller = (new RequestAttributeScopeResolver())->resolve(new FakeRequest(attributes: ['user' => 'alice']));

        Assert::false($caller->equals(new IdempotencyScope('alice')));
    }
}
