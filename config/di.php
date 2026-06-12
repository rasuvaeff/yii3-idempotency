<?php

declare(strict_types=1);

use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Rasuvaeff\Yii3Idempotency\HeaderIdempotencyKeyExtractor;
use Rasuvaeff\Yii3Idempotency\IdempotencyKeyExtractor;
use Rasuvaeff\Yii3Idempotency\IdempotencyMiddleware;
use Rasuvaeff\Yii3Idempotency\IdempotencyPolicy;
use Rasuvaeff\Yii3Idempotency\IdempotencyStorage;

/** @var array $params */

return [
    HeaderIdempotencyKeyExtractor::class => [
        '__construct()' => [
            'headerName' => $params['rasuvaeff/yii3-idempotency']['headerName'] ?? 'Idempotency-Key',
        ],
    ],
    IdempotencyKeyExtractor::class => HeaderIdempotencyKeyExtractor::class,
    IdempotencyMiddleware::class => static fn (
        IdempotencyKeyExtractor $keyExtractor,
        IdempotencyStorage $storage,
        ResponseFactoryInterface $responseFactory,
        ClockInterface $clock,
    ): IdempotencyMiddleware => new IdempotencyMiddleware(
        keyExtractor: $keyExtractor,
        storage: $storage,
        responseFactory: $responseFactory,
        clock: $clock,
        policy: IdempotencyPolicy::fromConfigValue(
            $params['rasuvaeff/yii3-idempotency']['policy'] ?? 'pass_through',
        ),
        ttlSeconds: (int) ($params['rasuvaeff/yii3-idempotency']['ttlSeconds'] ?? 3600),
        methods: $params['rasuvaeff/yii3-idempotency']['methods'] ?? ['POST', 'PUT', 'PATCH'],
    ),
];
