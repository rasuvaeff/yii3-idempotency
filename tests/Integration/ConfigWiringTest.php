<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3Idempotency\HeaderIdempotencyKeyExtractor;
use Rasuvaeff\Yii3Idempotency\IdempotencyKeyExtractor;
use Rasuvaeff\Yii3Idempotency\IdempotencyMiddleware;
use Rasuvaeff\Yii3Idempotency\IdempotencyStorage;
use Rasuvaeff\Yii3Idempotency\InMemoryIdempotencyStorage;
use Rasuvaeff\Yii3Idempotency\Tests\FakeClock;
use Rasuvaeff\Yii3Idempotency\Tests\FakeResponseFactory;

/**
 * Exercises the package `config/di.php`, which is covered by neither cs, psalm,
 * nor the unit suite. The core must not bind the swappable `IdempotencyStorage`
 * key — that belongs to exactly one backend package (yiisoft/config rejects
 * duplicate keys across vendor packages).
 */
#[CoversNothing]
final class ConfigWiringTest extends TestCase
{
    #[Test]
    public function bindsExtractorAliasAndMiddlewareOnly(): void
    {
        $definitions = $this->loadDi([]);

        $this->assertSame(
            [
                HeaderIdempotencyKeyExtractor::class,
                IdempotencyKeyExtractor::class,
                IdempotencyMiddleware::class,
            ],
            array_keys($definitions),
        );
    }

    #[Test]
    public function doesNotBindSwappableStorageKey(): void
    {
        $this->assertArrayNotHasKey(IdempotencyStorage::class, $this->loadDi([]));
    }

    #[Test]
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
        $this->assertIsCallable($factory);

        $clock = new FakeClock();
        $middleware = $factory(
            new HeaderIdempotencyKeyExtractor(),
            new InMemoryIdempotencyStorage($clock),
            new FakeResponseFactory(),
            $clock,
        );

        $this->assertInstanceOf(IdempotencyMiddleware::class, $middleware);
    }

    #[Test]
    public function middlewareFactoryUsesDefaultsWhenParamsAbsent(): void
    {
        $definitions = $this->loadDi([]);
        $factory = $definitions[IdempotencyMiddleware::class];
        $this->assertIsCallable($factory);

        $clock = new FakeClock();
        $middleware = $factory(
            new HeaderIdempotencyKeyExtractor(),
            new InMemoryIdempotencyStorage($clock),
            new FakeResponseFactory(),
            $clock,
        );

        $this->assertInstanceOf(IdempotencyMiddleware::class, $middleware);
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
