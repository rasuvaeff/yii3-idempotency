<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\Yii3Idempotency\HeaderIdempotencyKeyExtractor;
use Rasuvaeff\Yii3Idempotency\IdempotencyKey;

$extractor = new HeaderIdempotencyKeyExtractor();

$key = new IdempotencyKey('payment-123');

echo "Key: {$key->value}\n";
echo "Key valid: yes\n";

$fingerprint = \Rasuvaeff\Yii3Idempotency\IdempotencyFingerprint::fromRequest(
    new \Rasuvaeff\Yii3Idempotency\Tests\FakeRequest(
        method: 'POST',
        path: '/api/payments',
        body: '{"amount":100}',
    ),
);

echo "Fingerprint: {$fingerprint->hash}\n";
