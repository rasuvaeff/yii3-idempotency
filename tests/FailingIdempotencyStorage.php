<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency\Tests;

use Rasuvaeff\Yii3Idempotency\IdempotencyFingerprint;
use Rasuvaeff\Yii3Idempotency\IdempotencyKey;
use Rasuvaeff\Yii3Idempotency\IdempotencyRecord;
use Rasuvaeff\Yii3Idempotency\IdempotencyStorage;
use Rasuvaeff\Yii3Idempotency\InMemoryIdempotencyStorage;

/**
 * Delegates everything to a real storage but blows up on the selected writes, so
 * tests can assert that a claim never outlives a failed write and that a failing
 * cleanup never replaces the throwable the caller needs to see.
 *
 * @internal
 */
final readonly class FailingIdempotencyStorage implements IdempotencyStorage
{
    public function __construct(
        private InMemoryIdempotencyStorage $inner,
        private bool $failOnStore = true,
        private bool $failOnRelease = false,
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
        if ($this->failOnStore) {
            throw new \RuntimeException('storage is down');
        }

        $this->inner->store($record);
    }

    #[\Override]
    public function release(IdempotencyKey $key): void
    {
        if ($this->failOnRelease) {
            throw new \RuntimeException('release is down');
        }

        $this->inner->release($key);
    }
}
