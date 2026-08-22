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

        Assert::same($scope->name, '21:caller:identity:alice | 18:POST /api/payments');
    }

    /**
     * A single component is length-prefixed too: a pass-through would let a lone
     * resolver whose name reads `5:alpha | 4:beta` collide with the composition
     * of `alpha` and `beta`.
     */
    public function aSingleResolverIsLengthPrefixedToo(): void
    {
        $resolver = new CompositeScopeResolver(new IdempotencyScope('billing'));

        Assert::same($resolver->resolve(new FakeRequest())->name, '7:billing');
    }

    /**
     * Regression for the non-injective join: the separator is legal inside a
     * scope name, so `['a | b', 'c']` and `['a', 'b | c']` produced one name —
     * and therefore one shared idempotency record.
     */
    public function componentsCarryingTheSeparatorStayDistinct(): void
    {
        $left = (new CompositeScopeResolver(new IdempotencyScope('a | b'), new IdempotencyScope('c')))
            ->resolve(new FakeRequest());
        $right = (new CompositeScopeResolver(new IdempotencyScope('a'), new IdempotencyScope('b | c')))
            ->resolve(new FakeRequest());

        Assert::same($left->name, '5:a | b | 1:c');
        Assert::same($right->name, '1:a | 5:b | c');
        Assert::false($left->equals($right));
    }

    /**
     * The same collision one dimension further: a colon is legal in a name, so
     * the length prefix — not the colon — is what keeps the parts apart.
     */
    public function aComponentThatMimicsTheEncodingStaysDistinct(): void
    {
        $left = (new CompositeScopeResolver(new IdempotencyScope('1:a | 1:b')))->resolve(new FakeRequest());
        $right = (new CompositeScopeResolver(new IdempotencyScope('a'), new IdempotencyScope('b')))
            ->resolve(new FakeRequest());

        Assert::false($left->equals($right));
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

        Assert::same($scope->name, hash('sha256', '600:' . $left . ' | 600:' . $right));
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
