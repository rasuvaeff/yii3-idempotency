<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3Idempotency\IdempotencyKey;
use Rasuvaeff\Yii3Idempotency\IdempotencyRecord;
use Rasuvaeff\Yii3Idempotency\IdempotencyResponse;
use Rasuvaeff\Yii3Idempotency\IdempotencyStorage;
use Rasuvaeff\Yii3Idempotency\InMemoryIdempotencyStorage;

#[CoversClass(InMemoryIdempotencyStorage::class)]
final class InMemoryIdempotencyStorageTest extends TestCase
{
    private FakeClock $clock;

    private InMemoryIdempotencyStorage $storage;

    #[\Override]
    protected function setUp(): void
    {
        $this->clock = new FakeClock();
        $this->storage = new InMemoryIdempotencyStorage($this->clock);
    }

    #[Test]
    public function implementsInterface(): void
    {
        $this->assertInstanceOf(IdempotencyStorage::class, $this->storage);
    }

    #[Test]
    public function loadReturnsNullForUnknownKey(): void
    {
        $this->assertNull($this->storage->load(new IdempotencyKey('unknown')));
    }

    #[Test]
    public function claimReturnsTrueForNewKey(): void
    {
        $key = new IdempotencyKey('key-1');
        $fp = $this->createFingerprint('hash-1');

        $this->assertTrue($this->storage->claim($key, $fp));
    }

    #[Test]
    public function claimReturnsFalseForRepeatedClaim(): void
    {
        $key = new IdempotencyKey('key-1');
        $fp = $this->createFingerprint('hash-1');

        $this->storage->claim($key, $fp);

        $this->assertFalse($this->storage->claim($key, $fp));
    }

    #[Test]
    public function claimReturnsFalseForDifferentFingerprint(): void
    {
        $key = new IdempotencyKey('key-1');
        $fp1 = $this->createFingerprint('hash-1');
        $fp2 = $this->createFingerprint('hash-2');

        $this->storage->claim($key, $fp1);

        $this->assertFalse($this->storage->claim($key, $fp2));
    }

    #[Test]
    public function storeAndLoad(): void
    {
        $key = new IdempotencyKey('key-1');
        $record = $this->createRecord($key);

        $this->storage->store($record);
        $loaded = $this->storage->load($key);

        $this->assertNotNull($loaded);
        $this->assertTrue($loaded->key->equals($key));
    }

    #[Test]
    public function loadReturnsNullForExpiredRecord(): void
    {
        $key = new IdempotencyKey('key-1');
        $record = $this->createRecord($key, ttlSeconds: 60);

        $this->storage->store($record);
        $this->clock->advance(60);

        $this->assertNull($this->storage->load($key));
    }

    #[Test]
    public function releaseAllowsClaimAgain(): void
    {
        $key = new IdempotencyKey('key-1');
        $fp = $this->createFingerprint('hash-1');

        $this->storage->claim($key, $fp);
        $this->storage->release($key);

        $this->assertTrue($this->storage->claim($key, $fp));
    }

    private function createFingerprint(string $hash): \Rasuvaeff\Yii3Idempotency\IdempotencyFingerprint
    {
        return new \Rasuvaeff\Yii3Idempotency\IdempotencyFingerprint($hash);
    }

    private function createRecord(IdempotencyKey $key, int $ttlSeconds = 3600): IdempotencyRecord
    {
        return IdempotencyRecord::create(
            key: $key,
            fingerprint: $this->createFingerprint('hash'),
            response: new IdempotencyResponse(200, [], 'body'),
            clock: $this->clock,
            ttlSeconds: $ttlSeconds,
        );
    }
}
