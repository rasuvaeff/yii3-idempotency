<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Turns a {@see FailureKind::Domain} failure into the response the client sees.
 *
 * The middleware caches exactly what the renderer returns, so the first attempt
 * and every replay within the TTL are byte-identical. Returning `null` declines
 * the failure: the claim is released and the original throwable is rethrown.
 *
 * @api
 */
interface DomainFailureRenderer
{
    public function render(\Throwable $failure, ServerRequestInterface $request): ?ResponseInterface;
}
