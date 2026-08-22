<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency\Tests\Integration;

use Rasuvaeff\Yii3Idempotency\CompositeScopeResolver;
use Rasuvaeff\Yii3Idempotency\HeaderIdempotencyKeyExtractor;
use Rasuvaeff\Yii3Idempotency\IdempotencyKey;
use Rasuvaeff\Yii3Idempotency\IdempotencyKeyExtractor;
use Rasuvaeff\Yii3Idempotency\IdempotencyMiddleware;
use Rasuvaeff\Yii3Idempotency\IdempotencyScopeResolver;
use Rasuvaeff\Yii3Idempotency\IdempotencyStorage;
use Rasuvaeff\Yii3Idempotency\InMemoryIdempotencyStorage;
use Rasuvaeff\Yii3Idempotency\RequestAttributeScopeResolver;
use Rasuvaeff\Yii3Idempotency\SharedKeyspaceScopeResolver;
use Rasuvaeff\Yii3Idempotency\Tests\FakeClock;
use Rasuvaeff\Yii3Idempotency\Tests\FakeRequest;
use Rasuvaeff\Yii3Idempotency\Tests\FakeResponseFactory;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Test;

/**
 * Exercises the package `config/di.php`, which is covered by neither cs, psalm,
 * nor the unit suite. The core must not bind the swappable `IdempotencyStorage`
 * key — that belongs to exactly one backend package (yiisoft/config rejects
 * duplicate keys across vendor packages).
 */
#[Test]
#[CoversNothing]
final class ConfigWiringTest
{
    public function bindsExtractorAliasScopeResolverAndMiddlewareOnly(): void
    {
        $definitions = $this->loadDi([]);

        Assert::same(
            array_keys($definitions),
            [
                HeaderIdempotencyKeyExtractor::class,
                IdempotencyKeyExtractor::class,
                IdempotencyScopeResolver::class,
                IdempotencyMiddleware::class,
            ],
        );
    }

    public function doesNotBindSwappableStorageKey(): void
    {
        Assert::array($this->loadDi([]))->doesNotHaveKeys(IdempotencyStorage::class);
    }

    public function extractorIsAliasedToTheHeaderExtractor(): void
    {
        Assert::same($this->loadDi([])[IdempotencyKeyExtractor::class], HeaderIdempotencyKeyExtractor::class);
    }

    /**
     * The whole point of the fail-closed default: an application that never
     * decided how the keyspace is partitioned must not silently get one shared
     * by every caller.
     */
    public function scopeResolverFactoryRefusesAnUnconfiguredCaller(): void
    {
        $factory = $this->loadDi([])[IdempotencyScopeResolver::class];
        Assert::true(is_callable($factory));

        try {
            $factory();
            Assert::fail('Expected \InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('callerAttribute');
        }
    }

    public function scopeResolverFactoryBuildsAPerCallerScope(): void
    {
        $resolver = $this->scopeResolver(['callerAttribute' => 'user', 'scope' => null]);

        Assert::instanceOf($resolver, RequestAttributeScopeResolver::class);
        Assert::same($resolver->resolve(new FakeRequest(attributes: ['user' => 'alice']))->name, 'caller:alice');
    }

    public function scopeResolverFactoryHonoursTheAnonymousName(): void
    {
        $resolver = $this->scopeResolver([
            'callerAttribute' => 'user',
            'anonymousCaller' => 'guest',
            'scope' => null,
        ]);

        Assert::same($resolver->resolve(new FakeRequest())->name, 'caller:guest');
    }

    public function scopeResolverFactoryHonoursTheSharedOptOut(): void
    {
        $resolver = $this->scopeResolver(['callerAttribute' => false, 'scope' => null]);

        Assert::instanceOf($resolver, SharedKeyspaceScopeResolver::class);
    }

    public function scopeResolverFactoryCombinesCallerAndAutoEndpointScope(): void
    {
        $resolver = $this->scopeResolver(['callerAttribute' => 'user']);

        Assert::instanceOf($resolver, CompositeScopeResolver::class);
        Assert::same(
            $resolver->resolve(new FakeRequest(
                method: 'POST',
                path: '/api/orders',
                attributes: ['user' => 'alice'],
            ))->name,
            'caller:alice | POST /api/orders',
        );
    }

    public function scopeResolverFactoryCombinesCallerAndNamedScope(): void
    {
        $resolver = $this->scopeResolver(['callerAttribute' => false, 'scope' => 'orders']);

        Assert::same($resolver->resolve(new FakeRequest())->name, 'shared | orders');
    }

    public function middlewareFactoryBuildsMiddleware(): void
    {
        $definitions = $this->loadDi([
            'rasuvaeff/yii3-idempotency' => [
                'headerName' => 'X-Request-Id',
                'policy' => 'reject',
                'ttlSeconds' => 60,
                'callerAttribute' => 'user',
            ],
        ]);

        $factory = $definitions[IdempotencyMiddleware::class];
        Assert::true(is_callable($factory));

        $clock = new FakeClock();
        $middleware = $factory(
            new HeaderIdempotencyKeyExtractor(),
            new InMemoryIdempotencyStorage($clock),
            new FakeResponseFactory(),
            $clock,
            new SharedKeyspaceScopeResolver(),
        );

        Assert::instanceOf($middleware, IdempotencyMiddleware::class);
    }

    public function middlewareFactoryUsesDefaultsWhenParamsAbsent(): void
    {
        $definitions = $this->loadDi([]);
        $factory = $definitions[IdempotencyMiddleware::class];
        Assert::true(is_callable($factory));

        $clock = new FakeClock();
        $middleware = $factory(
            new HeaderIdempotencyKeyExtractor(),
            new InMemoryIdempotencyStorage($clock),
            new FakeResponseFactory(),
            $clock,
            new SharedKeyspaceScopeResolver(),
        );

        Assert::instanceOf($middleware, IdempotencyMiddleware::class);
    }

    /**
     * The wiring, end to end: a key from the header, namespaced by the caller
     * the application put in the request attribute.
     */
    public function wiredMiddlewareNamespacesTheKeyByCaller(): void
    {
        $resolver = $this->scopeResolver(['callerAttribute' => 'user', 'scope' => null]);

        Assert::same(
            $resolver->resolve(new FakeRequest(attributes: ['user' => 'alice']))
                ->apply(new IdempotencyKey('key-1'))
                ->value,
            hash('sha256', "caller:alice\0key-1"),
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function scopeResolver(array $config): IdempotencyScopeResolver
    {
        $factory = $this->loadDi(['rasuvaeff/yii3-idempotency' => $config])[IdempotencyScopeResolver::class];
        Assert::true(is_callable($factory));

        $resolver = $factory();
        Assert::instanceOf($resolver, IdempotencyScopeResolver::class);

        return $resolver;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function loadDi(array $params): array
    {
        return require dirname(__DIR__, 2) . '/config/di.php';
    }
}
