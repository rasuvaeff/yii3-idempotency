<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency;

use Psr\Clock\ClockInterface;

/**
 * @api
 */
final readonly class IdempotencyRecord
{
    private function __construct(
        public IdempotencyKey $key,
        public IdempotencyFingerprint $fingerprint,
        public IdempotencyResponse $response,
        public \DateTimeImmutable $expiresAt,
    ) {}

    public static function create(
        IdempotencyKey $key,
        IdempotencyFingerprint $fingerprint,
        IdempotencyResponse $response,
        ClockInterface $clock,
        int $ttlSeconds,
    ): self {
        return new self(
            key: $key,
            fingerprint: $fingerprint,
            response: $response,
            expiresAt: $clock->now()->modify("+{$ttlSeconds} seconds"),
        );
    }

    public function isExpired(ClockInterface $clock): bool
    {
        return $clock->now() >= $this->expiresAt;
    }
}
