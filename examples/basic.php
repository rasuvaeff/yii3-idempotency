<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\Yii3Idempotency\IdempotencyKey;
use Rasuvaeff\Yii3Idempotency\IdempotencyPolicy;

$key = new IdempotencyKey('payment-123');

echo "Key: {$key->value}\n";
echo 'Policy: ' . IdempotencyPolicy::PassThrough->name . "\n";
