<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Derives the scope from the request target: `POST /api/orders`.
 *
 * The query string is deliberately excluded — it already participates in
 * {@see IdempotencyFingerprint}, where a mismatch is a 422 rather than a
 * separate record.
 *
 * @api
 */
final readonly class RequestTargetScopeResolver implements IdempotencyScopeResolver
{
    #[\Override]
    public function resolve(ServerRequestInterface $request): IdempotencyScope
    {
        $path = $request->getUri()->getPath();

        return new IdempotencyScope(
            strtoupper($request->getMethod()) . ' ' . ($path === '' ? '/' : $path),
        );
    }
}
