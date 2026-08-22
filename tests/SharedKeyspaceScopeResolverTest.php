<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency\Tests;

use Rasuvaeff\Yii3Idempotency\IdempotencyScopeResolver;
use Rasuvaeff\Yii3Idempotency\SharedKeyspaceScopeResolver;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(SharedKeyspaceScopeResolver::class)]
final class SharedKeyspaceScopeResolverTest
{
    public function implementsResolver(): void
    {
        Assert::instanceOf(new SharedKeyspaceScopeResolver(), IdempotencyScopeResolver::class);
    }

    public function resolvesToOneNamespaceForEveryCaller(): void
    {
        $resolver = new SharedKeyspaceScopeResolver();

        $alice = $resolver->resolve(new FakeRequest(path: '/a', attributes: ['user' => 'alice']));
        $bob = $resolver->resolve(new FakeRequest(path: '/b', attributes: ['user' => 'bob']));

        Assert::same($alice->name, 'shared');
        Assert::true($alice->equals($bob));
    }
}
