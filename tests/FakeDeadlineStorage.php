<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency\Tests;

use Rasuvaeff\Yii3Idempotency\ClaimedDeadlineProvider;
use Rasuvaeff\Yii3Idempotency\ClaimedFingerprintProvider;
use Rasuvaeff\Yii3Idempotency\IdempotencyFingerprint;
use Rasuvaeff\Yii3Idempotency\IdempotencyKey;
use Rasuvaeff\Yii3Idempotency\IdempotencyRecord;
use Rasuvaeff\Yii3Idempotency\IdempotencyStorage;

/**
 * A storage that answers the claim-deadline capability with a value the test
 * controls, the way a persistent backend with a real claim TTL would.
 *
 * @internal
 */
final class FakeDeadlineStorage implements IdempotencyStorage, ClaimedFingerprintProvider, ClaimedDeadlineProvider
{
    public ?\DateTimeImmutable $deadline = null;

    public function __construct(
        private IdempotencyStorage $delegate,
    ) {}

    #[\Override]
    public function load(IdempotencyKey $key): ?IdempotencyRecord
    {
        return $this->delegate->load($key);
    }

    #[\Override]
    public function claim(IdempotencyKey $key, IdempotencyFingerprint $fingerprint): bool
    {
        return $this->delegate->claim($key, $fingerprint);
    }

    #[\Override]
    public function store(IdempotencyRecord $record): void
    {
        $this->delegate->store($record);
    }

    #[\Override]
    public function release(IdempotencyKey $key): void
    {
        $this->delegate->release($key);
    }

    #[\Override]
    public function claimedFingerprint(IdempotencyKey $key): ?IdempotencyFingerprint
    {
        return $this->delegate instanceof ClaimedFingerprintProvider
            ? $this->delegate->claimedFingerprint($key)
            : null;
    }

    #[\Override]
    public function claimedDeadline(IdempotencyKey $key): ?\DateTimeImmutable
    {
        return $this->deadline;
    }
}
