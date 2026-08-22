<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Puts every caller in one keyspace: the pre-2.0 behaviour, kept as an explicit
 * opt-out rather than a default.
 *
 * Safe only when a single principal can reach the middleware — a single-tenant
 * deployment, an internal service with one trusted client, or an endpoint whose
 * responses hold nothing caller-private. Anywhere else this lets one client
 * replay another client's cached response and occupy another client's key; see
 * {@see RequestAttributeScopeResolver}.
 *
 * @api
 */
final readonly class SharedKeyspaceScopeResolver implements IdempotencyScopeResolver
{
    public const string NAME = 'shared';

    #[\Override]
    public function resolve(ServerRequestInterface $request): IdempotencyScope
    {
        return new IdempotencyScope(self::NAME);
    }
}
