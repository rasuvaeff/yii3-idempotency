<?php

declare(strict_types=1);

return [
    'rasuvaeff/yii3-idempotency' => [
        'headerName' => 'Idempotency-Key',
        'policy' => 'pass_through',
        'ttlSeconds' => 3600,
        'methods' => ['POST', 'PUT', 'PATCH'],
        // REQUIRED — the container refuses to build the middleware while this is null.
        // A request-attribute name (for example 'user'): keys are namespaced by the
        // authenticated principal found there, so one client can never replay another
        // client's cached response.
        // false: every caller shares one keyspace (the 1.x behaviour). Only safe for a
        // single-tenant deployment or a fully trusted single client.
        'callerAttribute' => null,
        // Scope name for requests that carry no principal; they share it among
        // themselves, because there is no identity to separate them by.
        'anonymousCaller' => 'anonymous',
        // Endpoint namespace applied on top of the caller.
        // 'auto' — "METHOD /path"; null — one namespace for every endpoint;
        // any other string — an explicit scope name shared by related endpoints
        'scope' => 'auto',
    ],
];
