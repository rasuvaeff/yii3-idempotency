<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency\Tests;

use Rasuvaeff\Yii3Idempotency\IdempotencyFingerprint;
use Rasuvaeff\Yii3Idempotency\IdempotencyKey;
use Rasuvaeff\Yii3Idempotency\IdempotencyRecord;
use Rasuvaeff\Yii3Idempotency\IdempotencyResponse;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(IdempotencyRecord::class)]
final class IdempotencyRecordTest
{
    public function createsWithTtl(): void
    {
        $clock = new FakeClock();
        $key = new IdempotencyKey('test-key');
        $fingerprint = new IdempotencyFingerprint('hash123');
        $response = new IdempotencyResponse(200, [], 'body');

        $record = IdempotencyRecord::create(
            key: $key,
            fingerprint: $fingerprint,
            response: $response,
            clock: $clock,
            ttlSeconds: 3600,
        );

        Assert::same($record->key, $key);
        Assert::same($record->fingerprint, $fingerprint);
        Assert::same($record->response, $response);
        Assert::same($record->expiresAt->format('c'), '2025-01-01T01:00:00+00:00');
    }

    public function isNotExpiredBeforeExpiry(): void
    {
        $clock = new FakeClock();
        $record = $this->createRecord($clock, ttlSeconds: 600);

        $clock->advance(599);

        Assert::false($record->isExpired($clock));
    }

    public function isExpiredAtExpiry(): void
    {
        $clock = new FakeClock();
        $record = $this->createRecord($clock, ttlSeconds: 600);

        $clock->advance(600);

        Assert::true($record->isExpired($clock));
    }

    public function isExpiredAfterExpiry(): void
    {
        $clock = new FakeClock();
        $record = $this->createRecord($clock, ttlSeconds: 600);

        $clock->advance(601);

        Assert::true($record->isExpired($clock));
    }

    public function restoresWithExplicitExpiry(): void
    {
        $key = new IdempotencyKey('test-key');
        $fingerprint = new IdempotencyFingerprint('hash123');
        $response = new IdempotencyResponse(200, [], 'body');
        $expiresAt = new \DateTimeImmutable('2025-01-01 02:30:00+00:00');

        $record = IdempotencyRecord::restore(
            key: $key,
            fingerprint: $fingerprint,
            response: $response,
            expiresAt: $expiresAt,
        );

        Assert::same($record->key, $key);
        Assert::same($record->fingerprint, $fingerprint);
        Assert::same($record->response, $response);
        Assert::same($record->expiresAt, $expiresAt);
    }

    public function restoredRecordRespectsExpiry(): void
    {
        $clock = new FakeClock();

        $record = IdempotencyRecord::restore(
            key: new IdempotencyKey('test-key'),
            fingerprint: new IdempotencyFingerprint('hash'),
            response: new IdempotencyResponse(200, [], 'body'),
            expiresAt: $clock->now()->modify('+600 seconds'),
        );

        Assert::false($record->isExpired($clock));

        $clock->advance(600);

        Assert::true($record->isExpired($clock));
    }

    private function createRecord(FakeClock $clock, int $ttlSeconds = 3600): IdempotencyRecord
    {
        return IdempotencyRecord::create(
            key: new IdempotencyKey('test-key'),
            fingerprint: new IdempotencyFingerprint('hash'),
            response: new IdempotencyResponse(200, [], 'body'),
            clock: $clock,
            ttlSeconds: $ttlSeconds,
        );
    }
}
