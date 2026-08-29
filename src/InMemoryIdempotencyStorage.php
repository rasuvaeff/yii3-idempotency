<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency;

use Psr\Clock\ClockInterface;

/**
 * @api
 */
final class InMemoryIdempotencyStorage implements IdempotencyStorage, ClaimedFingerprintProvider
{
    /** @var array<string, IdempotencyRecord> */
    private array $records = [];

    /** @var array<string, IdempotencyFingerprint> */
    private array $claims = [];

    public function __construct(
        private readonly ClockInterface $clock,
    ) {}

    #[\Override]
    public function load(IdempotencyKey $key): ?IdempotencyRecord
    {
        $record = $this->records[$key->value] ?? null;

        if ($record === null) {
            return null;
        }

        if ($record->isExpired($this->clock)) {
            unset($this->records[$key->value], $this->claims[$key->value]);

            return null;
        }

        return $record;
    }

    #[\Override]
    public function claim(IdempotencyKey $key, IdempotencyFingerprint $fingerprint): bool
    {
        // A stored record blocks the claim the same way the unique primary key
        // does in a persistent adapter: the key is spent until it expires and
        // `load()` cleans it up.
        if (isset($this->claims[$key->value]) || isset($this->records[$key->value])) {
            return false;
        }

        $this->claims[$key->value] = $fingerprint;

        return true;
    }

    #[\Override]
    public function store(IdempotencyRecord $record): void
    {
        $this->records[$record->key->value] = $record;
        unset($this->claims[$record->key->value]);
    }

    #[\Override]
    public function claimedFingerprint(IdempotencyKey $key): ?IdempotencyFingerprint
    {
        return $this->claims[$key->value] ?? null;
    }

    #[\Override]
    public function release(IdempotencyKey $key): void
    {
        unset($this->claims[$key->value]);
    }
}
