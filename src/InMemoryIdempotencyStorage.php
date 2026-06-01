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

    /** @var array<string, string> */
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
        $existing = $this->claims[$key->value] ?? null;

        if ($existing === null) {
            $this->claims[$key->value] = $fingerprint->hash;

            return true;
        }

        return $existing === $fingerprint->hash;
    }

    #[\Override]
    public function store(IdempotencyRecord $record): void
    {
        $this->records[$record->key->value] = $record;
    }
}
