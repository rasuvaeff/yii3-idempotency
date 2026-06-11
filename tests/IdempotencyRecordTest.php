<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3Idempotency\IdempotencyKey;
use Rasuvaeff\Yii3Idempotency\IdempotencyRecord;
use Rasuvaeff\Yii3Idempotency\IdempotencyResponse;

#[CoversClass(IdempotencyRecord::class)]
final class IdempotencyRecordTest extends TestCase
{
    #[Test]
    public function createsWithTtl(): void
    {
        $clock = new FakeClock();
        $key = new IdempotencyKey('test-key');
        $fingerprint = new \Rasuvaeff\Yii3Idempotency\IdempotencyFingerprint('hash123');
        $response = new IdempotencyResponse(200, [], 'body');

        $record = IdempotencyRecord::create(
            key: $key,
            fingerprint: $fingerprint,
            response: $response,
            clock: $clock,
            ttlSeconds: 3600,
        );

        $this->assertSame($key, $record->key);
        $this->assertSame($fingerprint, $record->fingerprint);
        $this->assertSame($response, $record->response);
        $this->assertSame('2025-01-01T01:00:00+00:00', $record->expiresAt->format('c'));
    }

    #[Test]
    public function isNotExpiredBeforeExpiry(): void
    {
        $clock = new FakeClock();
        $record = $this->createRecord($clock, ttlSeconds: 600);

        $clock->advance(599);

        $this->assertFalse($record->isExpired($clock));
    }

    #[Test]
    public function isExpiredAtExpiry(): void
    {
        $clock = new FakeClock();
        $record = $this->createRecord($clock, ttlSeconds: 600);

        $clock->advance(600);

        $this->assertTrue($record->isExpired($clock));
    }

    #[Test]
    public function isExpiredAfterExpiry(): void
    {
        $clock = new FakeClock();
        $record = $this->createRecord($clock, ttlSeconds: 600);

        $clock->advance(601);

        $this->assertTrue($record->isExpired($clock));
    }

    #[Test]
    public function restoresWithExplicitExpiry(): void
    {
        $key = new IdempotencyKey('test-key');
        $fingerprint = new \Rasuvaeff\Yii3Idempotency\IdempotencyFingerprint('hash123');
        $response = new IdempotencyResponse(200, [], 'body');
        $expiresAt = new \DateTimeImmutable('2025-01-01 02:30:00+00:00');

        $record = IdempotencyRecord::restore(
            key: $key,
            fingerprint: $fingerprint,
            response: $response,
            expiresAt: $expiresAt,
        );

        $this->assertSame($key, $record->key);
        $this->assertSame($fingerprint, $record->fingerprint);
        $this->assertSame($response, $record->response);
        $this->assertSame($expiresAt, $record->expiresAt);
    }

    #[Test]
    public function restoredRecordRespectsExpiry(): void
    {
        $clock = new FakeClock();

        $record = IdempotencyRecord::restore(
            key: new IdempotencyKey('test-key'),
            fingerprint: new \Rasuvaeff\Yii3Idempotency\IdempotencyFingerprint('hash'),
            response: new IdempotencyResponse(200, [], 'body'),
            expiresAt: $clock->now()->modify('+600 seconds'),
        );

        $this->assertFalse($record->isExpired($clock));

        $clock->advance(600);

        $this->assertTrue($record->isExpired($clock));
    }

    private function createRecord(FakeClock $clock, int $ttlSeconds = 3600): IdempotencyRecord
    {
        return IdempotencyRecord::create(
            key: new IdempotencyKey('test-key'),
            fingerprint: new \Rasuvaeff\Yii3Idempotency\IdempotencyFingerprint('hash'),
            response: new IdempotencyResponse(200, [], 'body'),
            clock: $clock,
            ttlSeconds: $ttlSeconds,
        );
    }
}
