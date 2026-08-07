<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\Yii3Idempotency\DefaultFailureClassifier;
use Rasuvaeff\Yii3Idempotency\FailureKind;
use Rasuvaeff\Yii3Idempotency\IdempotencyKey;
use Rasuvaeff\Yii3Idempotency\IdempotencyScope;
use Rasuvaeff\Yii3Idempotency\PayloadIdempotencyKeyExtractor;
use Rasuvaeff\Yii3Idempotency\RetryableFailure;

echo "== Key scoping ==\n";

$key = new IdempotencyKey('order-42');
$orders = new IdempotencyScope('orders');
$payments = new IdempotencyScope('payments');

echo "Raw key:            {$key->value}\n";
echo "Scoped as orders:   {$orders->apply($key)->value}\n";
echo "Scoped as payments: {$payments->apply($key)->value}\n";
echo 'Same storage key: ' . ($orders->apply($key)->equals($payments->apply($key)) ? 'yes' : 'no') . "\n";

echo "\n== Failure classification ==\n";

$classifier = new DefaultFailureClassifier([
    LogicException::class => FailureKind::Bug,
]);

$failures = [
    'domain (plain exception)' => new RuntimeException('payment declined'),
    'retryable (marker)' => new class ('gateway timeout') extends RuntimeException implements RetryableFailure {},
    'bug (override)' => new LogicException('impossible state'),
    'infrastructure (error)' => new Error('out of memory'),
];

foreach ($failures as $label => $failure) {
    $kind = $classifier->classify($failure);

    echo str_pad($label, 26) . ' => ' . str_pad($kind->name, 15)
        . ($kind === FailureKind::Domain ? 'cached and replayed' : 'claim released, retryable') . "\n";
}

echo "\n== Payload keys ==\n";

new PayloadIdempotencyKeyExtractor('command.orderId');
echo "Accepted path: command.orderId\n";

try {
    new PayloadIdempotencyKeyExtractor('command..orderId');
} catch (InvalidArgumentException $e) {
    echo "Rejected path: {$e->getMessage()}\n";
}
