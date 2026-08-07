<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency;

use Psr\Http\Message\ServerRequestInterface;

/**
 * @api
 */
interface IdempotencyScopeResolver
{
    public function resolve(ServerRequestInterface $request): IdempotencyScope;
}
