<?php

declare(strict_types=1);

use Rasuvaeff\Yii3Idempotency\HeaderIdempotencyKeyExtractor;
use Rasuvaeff\Yii3Idempotency\IdempotencyKeyExtractor;
use Rasuvaeff\Yii3Idempotency\IdempotencyMiddleware;

/** @var array $params */

return [
    IdempotencyKeyExtractor::class => [
        '__construct()' => [
            'headerName' => $params['rasuvaeff/yii3-idempotency']['headerName'] ?? 'Idempotency-Key',
        ],
    ],
    IdempotencyMiddleware::class => IdempotencyMiddleware::class,
];
