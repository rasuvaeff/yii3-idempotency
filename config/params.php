<?php

declare(strict_types=1);

return [
    'rasuvaeff/yii3-idempotency' => [
        'headerName' => 'Idempotency-Key',
        'policy' => 'pass_through',
        'ttlSeconds' => 3600,
    ],
];
