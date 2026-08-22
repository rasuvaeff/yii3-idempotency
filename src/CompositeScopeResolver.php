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
     * @var non-empty-array<array-key, IdempotencyScopeResolver>
     */
    private array $resolvers;

    public function __construct(IdempotencyScopeResolver ...$resolvers)
    {
        if ($resolvers === []) {
            throw new \InvalidArgumentException('Composite scope needs at least one resolver');
        }

        // A variadic parameter is already a list, so no re-indexing is needed.
        $this->resolvers = $resolvers;
    }

    /**
     * Every component is length-prefixed before it is joined.
     *
     * The separator is legal *inside* a scope name, so a plain join is not
     * injective: `['a | b', 'c']` and `['a', 'b | c']` produce the same name,
     * and therefore the same scoped storage key — two different callers or
     * endpoints sharing one idempotency record. `<length>:<name>` makes the
     * composition decodable, so distinct ordered inputs stay distinct.
     */
    #[\Override]
    public function resolve(ServerRequestInterface $request): IdempotencyScope
    {
        $name = implode(self::SEPARATOR, array_map(
            static function (IdempotencyScopeResolver $resolver) use ($request): string {
                $part = $resolver->resolve($request)->name;

                return \strlen($part) . ':' . $part;
            },
            $this->resolvers,
        ));

        // `of()` rather than the constructor: two dimensions that each fit the
        // limit can exceed it once joined, and that must not become a 500.
        return IdempotencyScope::of($name);
    }
}
