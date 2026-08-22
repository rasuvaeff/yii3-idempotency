<?php

declare(strict_types=1);

use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Rasuvaeff\Yii3Idempotency\CompositeScopeResolver;
use Rasuvaeff\Yii3Idempotency\HeaderIdempotencyKeyExtractor;
use Rasuvaeff\Yii3Idempotency\IdempotencyKeyExtractor;
use Rasuvaeff\Yii3Idempotency\IdempotencyMiddleware;
use Rasuvaeff\Yii3Idempotency\IdempotencyPolicy;
use Rasuvaeff\Yii3Idempotency\IdempotencyScope;
use Rasuvaeff\Yii3Idempotency\IdempotencyScopeResolver;
use Rasuvaeff\Yii3Idempotency\IdempotencyStorage;
use Rasuvaeff\Yii3Idempotency\RequestAttributeScopeResolver;
use Rasuvaeff\Yii3Idempotency\RequestTargetScopeResolver;
use Rasuvaeff\Yii3Idempotency\SharedKeyspaceScopeResolver;

/** @var array $params */

return [
    HeaderIdempotencyKeyExtractor::class => [
        '__construct()' => [
            'headerName' => $params['rasuvaeff/yii3-idempotency']['headerName'] ?? 'Idempotency-Key',
        ],
    ],
    IdempotencyKeyExtractor::class => HeaderIdempotencyKeyExtractor::class,
    IdempotencyScopeResolver::class => static function () use ($params): IdempotencyScopeResolver {
        $config = $params['rasuvaeff/yii3-idempotency'] ?? [];

        /** @var string|false|null $caller */
        $caller = $config['callerAttribute'] ?? null;

        // Fail closed. A keyspace shared by every caller lets one client replay
        // another client's cached response, and the replay path never reaches
        // the handler's authorization checks — so the choice is made here,
        // explicitly, or the container refuses to build the middleware.
        if ($caller === null) {
            throw new InvalidArgumentException(
                'rasuvaeff/yii3-idempotency: params key "callerAttribute" is required. '
                . 'Set it to the request attribute holding the authenticated principal (for example "user"), '
                . 'or to false to put every caller in one keyspace — only safe for a single-tenant deployment.',
            );
        }

        $resolvers = [
            $caller === false
                ? new SharedKeyspaceScopeResolver()
                : new RequestAttributeScopeResolver(
                    attribute: (string) $caller,
                    anonymous: (string) ($config['anonymousCaller'] ?? RequestAttributeScopeResolver::DEFAULT_ANONYMOUS),
                ),
        ];

        // `array_key_exists`, not `??`: `'scope' => null` is the documented way
        // to turn the endpoint dimension off, and `??` would read it as absent
        // and silently put 'auto' back.
        /** @var string|null $scope */
        $scope = \array_key_exists('scope', $config) ? $config['scope'] : 'auto';

        if ($scope === 'auto') {
            $resolvers[] = new RequestTargetScopeResolver();
        } elseif ($scope !== null) {
            $resolvers[] = new IdempotencyScope((string) $scope);
        }

        return \count($resolvers) === 1 ? $resolvers[0] : new CompositeScopeResolver(...$resolvers);
    },
    IdempotencyMiddleware::class => static fn (
        IdempotencyKeyExtractor $keyExtractor,
        IdempotencyStorage $storage,
        ResponseFactoryInterface $responseFactory,
        ClockInterface $clock,
        IdempotencyScopeResolver $scopeResolver,
    ): IdempotencyMiddleware => new IdempotencyMiddleware(
        keyExtractor: $keyExtractor,
        storage: $storage,
        responseFactory: $responseFactory,
        clock: $clock,
        scopeResolver: $scopeResolver,
        policy: IdempotencyPolicy::fromConfigValue(
            $params['rasuvaeff/yii3-idempotency']['policy'] ?? 'pass_through',
        ),
        ttlSeconds: (int) ($params['rasuvaeff/yii3-idempotency']['ttlSeconds'] ?? 3600),
        methods: $params['rasuvaeff/yii3-idempotency']['methods'] ?? ['POST', 'PUT', 'PATCH'],
    ),
];
