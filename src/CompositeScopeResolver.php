<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Joins several scopes into one, so a key can be namespaced by more than one
 * dimension at a time — the caller *and* the endpoint, for example.
 *
 * @api
 */
final readonly class CompositeScopeResolver implements IdempotencyScopeResolver
{
    /**
     * Printable on purpose: {@see IdempotencyScope} rejects control characters,
     * so joining with `"\0"` or `"\n"` would throw out of its own constructor.
     */
    private const string SEPARATOR = ' | ';

    /**
     * @var non-empty-list<IdempotencyScopeResolver>
     */
    private array $resolvers;

    public function __construct(IdempotencyScopeResolver ...$resolvers)
    {
        if ($resolvers === []) {
            throw new \InvalidArgumentException('Composite scope needs at least one resolver');
        }

        $this->resolvers = array_values($resolvers);
    }

    #[\Override]
    public function resolve(ServerRequestInterface $request): IdempotencyScope
    {
        $name = implode(self::SEPARATOR, array_map(
            static fn(IdempotencyScopeResolver $resolver): string => $resolver->resolve($request)->name,
            $this->resolvers,
        ));

        // `of()` rather than the constructor: two dimensions that each fit the
        // limit can exceed it once joined, and that must not become a 500.
        return IdempotencyScope::of($name);
    }
}
