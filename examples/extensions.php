<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\Yii3Idempotency\DefaultFailureClassifier;
use Rasuvaeff\Yii3Idempotency\FailureKind;
use Rasuvaeff\Yii3Idempotency\IdempotencyKey;
use Rasuvaeff\Yii3Idempotency\IdempotencyScope;
use Rasuvaeff\Yii3Idempotency\PayloadIdempotencyKeyExtractor;
use Rasuvaeff\Yii3Idempotency\RequestAttributeScopeResolver;
use Rasuvaeff\Yii3Idempotency\RetryableFailure;

echo "== Caller scoping ==\n";

$key = new IdempotencyKey('order-42');

// RequestAttributeScopeResolver builds exactly these names out of the request
// attribute holding the authenticated principal. They are spelled out here so
// the example needs no PSR-7 implementation.
$alice = new IdempotencyScope('caller:alice');
$mallory = new IdempotencyScope('caller:mallory');

echo "Raw key:            {$key->value}\n";
echo "Alice's key:        {$alice->apply($key)->value}\n";
echo "Mallory's key:      {$mallory->apply($key)->value}\n";
echo 'Same storage key: '
    . ($alice->apply($key)->equals($mallory->apply($key)) ? 'yes — cross-client replay!' : 'no') . "\n";
echo 'Resolver: ' . RequestAttributeScopeResolver::class . "(attribute: 'user')\n";

echo "\n== Endpoint scoping ==\n";

$orders = new IdempotencyScope('orders');
$payments = new IdempotencyScope('payments');

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
        . ($kind === FailureKind::Domain
            ? 'cacheable — needs a renderer that returns a response'
            : 'claim released, original throwable rethrown') . "\n";
}

echo "\n== Payload keys ==\n";

new PayloadIdempotencyKeyExtractor('command.orderId');
echo "Accepted path: command.orderId\n";

try {
    new PayloadIdempotencyKeyExtractor('command..orderId');
} catch (InvalidArgumentException $e) {
    echo "Rejected path: {$e->getMessage()}\n";
}
