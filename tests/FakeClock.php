<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency\Tests;

use Psr\Clock\ClockInterface;

/**
 * @internal
 */
final class FakeClock implements ClockInterface
{
    private \DateTimeImmutable $now;

    public function __construct(string $time = '2025-01-01 00:00:00')
    {
        $this->now = new \DateTimeImmutable($time, new \DateTimeZone('UTC'));
    }

    #[\Override]
    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify("+{$seconds} seconds");
    }
}
