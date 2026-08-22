<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Idempotency\IdempotencyScopeResolver;
use Rasuvaeff\Yii3Idempotency\RequestTargetScopeResolver;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(RequestTargetScopeResolver::class)]
final class RequestTargetScopeResolverTest
{
    private RequestTargetScopeResolver $resolver;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->resolver = new RequestTargetScopeResolver();
    }

    public function implementsResolverInterface(): void
    {
        Assert::instanceOf($this->resolver, IdempotencyScopeResolver::class);
    }

    public function buildsScopeFromMethodAndPath(): void
    {
        $scope = $this->resolver->resolve(new FakeRequest(method: 'POST', path: '/api/orders'));

        Assert::same($scope->name, 'POST /api/orders');
    }

    public function upperCasesTheMethod(): void
    {
        $scope = $this->resolver->resolve(new FakeRequest(method: 'post', path: '/api/orders'));

        Assert::same($scope->name, 'POST /api/orders');
    }

    public function ignoresTheQueryString(): void
    {
        $withQuery = $this->resolver->resolve(
            new FakeRequest(method: 'POST', path: '/api/orders', query: 'a=1'),
        );
        $withoutQuery = $this->resolver->resolve(
            new FakeRequest(method: 'POST', path: '/api/orders'),
        );

        Assert::true($withQuery->equals($withoutQuery));
    }

    public function substitutesRootForAnEmptyPath(): void
    {
        $scope = $this->resolver->resolve(new FakeRequest(method: 'POST', path: ''));

        Assert::same($scope->name, 'POST /');
    }

    public function differentPathsProduceDifferentScopes(): void
    {
        $orders = $this->resolver->resolve(new FakeRequest(method: 'POST', path: '/api/orders'));
        $payments = $this->resolver->resolve(new FakeRequest(method: 'POST', path: '/api/payments'));

        Assert::false($orders->equals($payments));
    }

    /**
     * A path long enough to push the scope name past the 1024-character limit
     * must not turn into a 500: the name is collapsed to its hash instead.
     */
    public function collapsesAnOverLongRequestTargetToAHash(): void
    {
        $path = '/' . str_repeat('p', 1200);

        $scope = (new RequestTargetScopeResolver())->resolve(new FakeRequest(method: 'POST', path: $path));

        Assert::same($scope->name, hash('sha256', 'POST ' . $path));
    }

    #[Property(runs: 200)]
    public function acceptsAnyRealisticRequestTarget(string $method, string $path): void
    {
        $scope = (new RequestTargetScopeResolver())->resolve(
            new FakeRequest(method: $method, path: $path),
        );

        Assert::true(str_starts_with($scope->name, strtoupper($method) . ' '));
    }

    /** @return array<string, ArbitraryInterface> */
    public static function acceptsAnyRealisticRequestTargetGenerators(): array
    {
        return [
            'method' => Gen::oneOf('POST', 'PUT', 'PATCH', 'post', 'Put'),
            'path' => Gen::map(
                Gen::stringAscii(),
                static fn(string $suffix): string => '/' . rawurlencode($suffix),
            ),
        ];
    }
}
