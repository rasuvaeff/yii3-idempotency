<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Wraps any extractor and namespaces the key it returns, so the same key value
 * reused against a different endpoint resolves to a different storage record
 * instead of replaying the wrong response.
 *
 * @api
 */
final readonly class ScopedIdempotencyKeyExtractor implements IdempotencyKeyExtractor
{
    public function __construct(
        private IdempotencyKeyExtractor $extractor,
        private IdempotencyScopeResolver $scopeResolver,
    ) {}

    #[\Override]
    public function extract(ServerRequestInterface $request): ?IdempotencyKey
    {
        $key = $this->extractor->extract($request);

        if (!$key instanceof IdempotencyKey) {
            return null;
        }

        return $this->scopeResolver->resolve($request)->apply($key);
    }
}
