<?php

declare(strict_types=1);

return [
    'rasuvaeff/yii3-idempotency' => [
        'headerName' => 'Idempotency-Key',
        'policy' => 'pass_through',
        'ttlSeconds' => 3600,
        'methods' => ['POST', 'PUT', 'PATCH'],
        // null — keys are global; 'auto' — scoped by "METHOD /path";
        // any other string — an explicit scope name shared by related endpoints
        'scope' => null,
    ],
];
