<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency\Tests;

use Rasuvaeff\Yii3Idempotency\CompositeScopeResolver;
use Rasuvaeff\Yii3Idempotency\IdempotencyScope;
use Rasuvaeff\Yii3Idempotency\IdempotencyScopeResolver;
use Rasuvaeff\Yii3Idempotency\RequestAttributeScopeResolver;
use Rasuvaeff\Yii3Idempotency\RequestTargetScopeResolver;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(CompositeScopeResolver::class)]
final class CompositeScopeResolverTest
{
    public function implementsResolver(): void
    {
        Assert::instanceOf(
            new CompositeScopeResolver(new RequestTargetScopeResolver()),
            IdempotencyScopeResolver::class,
        );
    }

    public function joinsEveryDimension(): void
    {
        $resolver = new CompositeScopeResolver(
            new RequestAttributeScopeResolver(),
            new RequestTargetScopeResolver(),
        );

        $scope = $resolver->resolve(new FakeRequest(
            method: 'POST',
            path: '/api/payments',
            attributes: ['user' => 'alice'],
        ));

        Assert::same($scope->name, 'caller:alice | POST /api/payments');
    }

    public function aSingleResolverPassesItsNameThrough(): void
    {
        $resolver = new CompositeScopeResolver(new IdempotencyScope('billing'));

        Assert::same($resolver->resolve(new FakeRequest())->name, 'billing');
    }

    public function rejectsAnEmptyComposition(): void
    {
        try {
            new CompositeScopeResolver();
            Assert::fail('Expected \InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            Assert::true(actual: true);
        }
    }

    /**
     * A long path plus a long principal would push the joined name past the
     * 1024-character limit of {@see IdempotencyScope}, which would throw at
     * request time. Collapsing to a hash keeps every dimension in play.
     */
    public function collapsesAnOverLongCompositionToAHash(): void
    {
        $left = str_repeat('a', 600);
        $right = str_repeat('b', 600);
        $resolver = new CompositeScopeResolver(new IdempotencyScope($left), new IdempotencyScope($right));

        $scope = $resolver->resolve(new FakeRequest());

        Assert::same($scope->name, hash('sha256', $left . ' | ' . $right));
    }

    public function collapsedCompositionsStayDistinct(): void
    {
        $tail = str_repeat('b', 600);

        $left = (new CompositeScopeResolver(new IdempotencyScope(str_repeat('a', 600)), new IdempotencyScope($tail)))
            ->resolve(new FakeRequest());
        $right = (new CompositeScopeResolver(new IdempotencyScope(str_repeat('c', 600)), new IdempotencyScope($tail)))
            ->resolve(new FakeRequest());

        Assert::false($left->equals($right));
    }
}
