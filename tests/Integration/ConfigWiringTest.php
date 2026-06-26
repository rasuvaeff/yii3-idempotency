<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency\Tests\Integration;

use Rasuvaeff\Yii3Idempotency\HeaderIdempotencyKeyExtractor;
use Rasuvaeff\Yii3Idempotency\IdempotencyKeyExtractor;
use Rasuvaeff\Yii3Idempotency\IdempotencyMiddleware;
use Rasuvaeff\Yii3Idempotency\IdempotencyStorage;
use Rasuvaeff\Yii3Idempotency\InMemoryIdempotencyStorage;
use Rasuvaeff\Yii3Idempotency\Tests\FakeClock;
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
    public function bindsExtractorAliasAndMiddlewareOnly(): void
    {
        $definitions = $this->loadDi([]);

        Assert::same(
            array_keys($definitions),
            [
                HeaderIdempotencyKeyExtractor::class,
                IdempotencyKeyExtractor::class,
                IdempotencyMiddleware::class,
            ],
        );
    }

    public function doesNotBindSwappableStorageKey(): void
    {
        Assert::array($this->loadDi([]))->doesNotHaveKeys(IdempotencyStorage::class);
    }

    public function middlewareFactoryBuildsMiddleware(): void
    {
        $definitions = $this->loadDi([
            'rasuvaeff/yii3-idempotency' => [
                'headerName' => 'X-Request-Id',
                'policy' => 'reject',
                'ttlSeconds' => 60,
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
        );

        Assert::instanceOf($middleware, IdempotencyMiddleware::class);
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
