<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency;

use Psr\Clock\ClockInterface;

/**
 * @api
 */
final class InMemoryIdempotencyStorage implements IdempotencyStorage
{
    /** @var array<string, IdempotencyRecord> */
    private array $records = [];

    /** @var array<string, true> */
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
        if (isset($this->claims[$key->value])) {
            return false;
        }

        $this->claims[$key->value] = true;

        return true;
    }

    #[\Override]
    public function store(IdempotencyRecord $record): void
    {
        $this->records[$record->key->value] = $record;
    }

    #[\Override]
    public function release(IdempotencyKey $key): void
    {
        unset($this->claims[$key->value]);
    }
}
