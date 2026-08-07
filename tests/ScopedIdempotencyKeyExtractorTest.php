<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Idempotency\HeaderIdempotencyKeyExtractor;
use Rasuvaeff\Yii3Idempotency\IdempotencyKeyExtractor;
use Rasuvaeff\Yii3Idempotency\IdempotencyScope;
use Rasuvaeff\Yii3Idempotency\RequestTargetScopeResolver;
use Rasuvaeff\Yii3Idempotency\ScopedIdempotencyKeyExtractor;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(ScopedIdempotencyKeyExtractor::class)]
final class ScopedIdempotencyKeyExtractorTest
{
    public function implementsExtractorInterface(): void
    {
        Assert::instanceOf($this->autoScoped(), IdempotencyKeyExtractor::class);
    }

    public function returnsNullWhenTheInnerExtractorFindsNoKey(): void
    {
        Assert::null($this->autoScoped()->extract(new FakeRequest(path: '/api/orders')));
    }

    public function namespacesTheKeyWithAStaticScope(): void
    {
        $extractor = new ScopedIdempotencyKeyExtractor(
            extractor: new HeaderIdempotencyKeyExtractor(),
            scopeResolver: new IdempotencyScope('orders'),
        );

        $key = $extractor->extract($this->request(path: '/api/orders', key: 'key-1'));

        Assert::same($key?->value, hash('sha256', "orders\0key-1"));
    }

    public function sameKeyOnDifferentEndpointsResolvesToDifferentStorageKeys(): void
    {
        $extractor = $this->autoScoped();

        $orders = $extractor->extract($this->request(path: '/api/orders', key: 'key-1'));
        $payments = $extractor->extract($this->request(path: '/api/payments', key: 'key-1'));

        Assert::same($orders?->value, hash('sha256', "POST /api/orders\0key-1"));
        Assert::same($payments?->value, hash('sha256', "POST /api/payments\0key-1"));
    }

    public function sameKeyOnTheSameEndpointResolvesToTheSameStorageKey(): void
    {
        $extractor = $this->autoScoped();

        $first = $extractor->extract($this->request(path: '/api/orders', key: 'key-1'));
        $second = $extractor->extract($this->request(path: '/api/orders', key: 'key-1'));

        Assert::same($first?->value, $second?->value);
    }

    public function aStaticScopeSharesOneNamespaceAcrossEndpoints(): void
    {
        $extractor = new ScopedIdempotencyKeyExtractor(
            extractor: new HeaderIdempotencyKeyExtractor(),
            scopeResolver: new IdempotencyScope('orders'),
        );

        $create = $extractor->extract($this->request(path: '/api/orders', key: 'key-1'));
        $cancel = $extractor->extract($this->request(path: '/api/orders/cancel', key: 'key-1'));

        Assert::same($create?->value, $cancel?->value);
    }

    /**
     * The guarantee item 2 of the roadmap asks for: under `auto` scoping, one key
     * value reused across two endpoints never lands on one storage record.
     */
    #[Property(runs: 300)]
    public function autoScopingNeverCollidesAcrossEndpoints(
        string $keyValue,
        string $pathA,
        string $pathB,
        string $methodA,
        string $methodB,
    ): void {
        $extractor = $this->autoScoped();

        $a = $extractor->extract($this->request(path: $pathA, key: $keyValue, method: $methodA));
        $b = $extractor->extract($this->request(path: $pathB, key: $keyValue, method: $methodB));

        $sameEndpoint = $pathA === $pathB && strtoupper($methodA) === strtoupper($methodB);

        Assert::same($a?->value === $b?->value, $sameEndpoint);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function autoScopingNeverCollidesAcrossEndpointsGenerators(): array
    {
        return [
            'keyValue' => Gen::oneOf('key-1', 'key-2', 'a'),
            'pathA' => Gen::oneOf('/api/orders', '/api/payments', '/api/orders/cancel'),
            'pathB' => Gen::oneOf('/api/orders', '/api/payments', '/api/orders/cancel'),
            'methodA' => Gen::oneOf('POST', 'PUT', 'patch'),
            'methodB' => Gen::oneOf('POST', 'PUT', 'patch'),
        ];
    }

    private function autoScoped(): ScopedIdempotencyKeyExtractor
    {
        return new ScopedIdempotencyKeyExtractor(
            extractor: new HeaderIdempotencyKeyExtractor(),
            scopeResolver: new RequestTargetScopeResolver(),
        );
    }

    private function request(string $path, string $key, string $method = 'POST'): FakeRequest
    {
        return new FakeRequest(
            method: $method,
            path: $path,
            headers: ['idempotency-key' => [$key]],
        );
    }
}
