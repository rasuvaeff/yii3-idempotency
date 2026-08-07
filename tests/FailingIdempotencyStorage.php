<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency\Tests;

use Rasuvaeff\Yii3Idempotency\IdempotencyFingerprint;
use Rasuvaeff\Yii3Idempotency\IdempotencyKey;
use Rasuvaeff\Yii3Idempotency\IdempotencyRecord;
use Rasuvaeff\Yii3Idempotency\IdempotencyStorage;
use Rasuvaeff\Yii3Idempotency\InMemoryIdempotencyStorage;

/**
 * Delegates everything to a real storage but blows up on `store()`, so tests can
 * assert the claim never outlives a failed write.
 *
 * @internal
 */
final readonly class FailingIdempotencyStorage implements IdempotencyStorage
{
    public function __construct(
        private InMemoryIdempotencyStorage $inner,
    ) {}

    #[\Override]
    public function load(IdempotencyKey $key): ?IdempotencyRecord
    {
        return $this->inner->load($key);
    }

    #[\Override]
    public function claim(IdempotencyKey $key, IdempotencyFingerprint $fingerprint): bool
    {
        return $this->inner->claim($key, $fingerprint);
    }

    #[\Override]
    public function store(IdempotencyRecord $record): void
    {
        throw new \RuntimeException('storage is down');
    }

    #[\Override]
    public function release(IdempotencyKey $key): void
    {
        $this->inner->release($key);
    }
}
