<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency;

use Psr\Http\Message\ServerRequestInterface;

/**
 * @api
 */
interface IdempotencyKeyExtractor
{
    public function extract(ServerRequestInterface $request): ?IdempotencyKey;
}
