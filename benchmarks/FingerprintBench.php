<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency\Benchmarks;

use Rasuvaeff\Yii3Idempotency\IdempotencyFingerprint;
use Rasuvaeff\Yii3Idempotency\Tests\FakeRequest;
use Testo\Bench;

/**
 * Compares IdempotencyFingerprint::fromRequest() cost for a small JSON body
 * vs a large one. Both compute sha256; the difference is how much data the
 * hash function processes.
 */
final class FingerprintBench
{
    #[Bench(
        callables: [
            'large_body' => [self::class, 'fromLargeBody'],
        ],
        calls: 1_000,
        iterations: 10,
    )]
    public static function fromSmallBody(): IdempotencyFingerprint
    {
        static $request = null;
        $request ??= new FakeRequest(
            method: 'POST',
            path: '/api/orders',
            body: '{"amount":100,"currency":"USD"}',
        );

        return IdempotencyFingerprint::fromRequest(request: $request);
    }

    public static function fromLargeBody(): IdempotencyFingerprint
    {
        static $request = null;
        if ($request === null) {
            $items = array_fill(
                start_index: 0,
                count: 50,
                value: ['id' => 1, 'amount' => 100, 'currency' => 'USD', 'status' => 'pending'],
            );
            $request = new FakeRequest(
                method: 'POST',
                path: '/api/orders/batch',
                body: (string) json_encode($items),
            );
        }

        return IdempotencyFingerprint::fromRequest(request: $request);
    }
}
