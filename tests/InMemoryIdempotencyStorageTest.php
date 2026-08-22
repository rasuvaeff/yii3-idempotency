<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\PropertyTesting\StateMachine\CommandSequence;
use Rasuvaeff\PropertyTesting\StateMachine\StateMachine;
use Rasuvaeff\Yii3Idempotency\IdempotencyFingerprint;
use Rasuvaeff\Yii3Idempotency\IdempotencyKey;
use Rasuvaeff\Yii3Idempotency\IdempotencyRecord;
use Rasuvaeff\Yii3Idempotency\IdempotencyResponse;
use Rasuvaeff\Yii3Idempotency\IdempotencyStorage;
use Rasuvaeff\Yii3Idempotency\InMemoryIdempotencyStorage;
use Rasuvaeff\Yii3Idempotency\Tests\Support\ClaimCommand;
use Rasuvaeff\Yii3Idempotency\Tests\Support\IdempotencyHarness;
use Rasuvaeff\Yii3Idempotency\Tests\Support\ReleaseCommand;
use Rasuvaeff\Yii3Idempotency\Tests\Support\StoreCommand;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(InMemoryIdempotencyStorage::class)]
final class InMemoryIdempotencyStorageTest
{
    private FakeClock $clock;

    private InMemoryIdempotencyStorage $storage;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->clock = new FakeClock();
        $this->storage = new InMemoryIdempotencyStorage($this->clock);
    }

    public function implementsInterface(): void
    {
        Assert::instanceOf($this->storage, IdempotencyStorage::class);
    }

    public function loadReturnsNullForUnknownKey(): void
    {
        Assert::null($this->storage->load(new IdempotencyKey('unknown')));
    }

    public function claimReturnsTrueForNewKey(): void
    {
        $key = new IdempotencyKey('key-1');
        $fp = $this->createFingerprint('hash-1');

        Assert::true($this->storage->claim($key, $fp));
    }

    public function claimReturnsFalseForRepeatedClaim(): void
    {
        $key = new IdempotencyKey('key-1');
        $fp = $this->createFingerprint('hash-1');

        $this->storage->claim($key, $fp);

        Assert::false($this->storage->claim($key, $fp));
    }

    public function claimReturnsFalseForDifferentFingerprint(): void
    {
        $key = new IdempotencyKey('key-1');
        $fp1 = $this->createFingerprint('hash-1');
        $fp2 = $this->createFingerprint('hash-2');

        $this->storage->claim($key, $fp1);

        Assert::false($this->storage->claim($key, $fp2));
    }

    public function storeAndLoad(): void
    {
        $key = new IdempotencyKey('key-1');
        $record = $this->createRecord($key);

        $this->storage->store($record);
        $loaded = $this->storage->load($key);

        Assert::notNull($loaded);
        Assert::true($loaded->key->equals($key));
    }

    public function loadReturnsNullForExpiredRecord(): void
    {
        $key = new IdempotencyKey('key-1');
        $record = $this->createRecord($key, ttlSeconds: 60);

        $this->storage->store($record);
        $this->clock->advance(60);

        Assert::null($this->storage->load($key));
    }

    public function releaseAllowsClaimAgain(): void
    {
        $key = new IdempotencyKey('key-1');
        $fp = $this->createFingerprint('hash-1');

        $this->storage->claim($key, $fp);
        $this->storage->release($key);

        Assert::true($this->storage->claim($key, $fp));
    }

    private function createFingerprint(string $hash): IdempotencyFingerprint
    {
        return new IdempotencyFingerprint($hash);
    }

    /**
     * Model-based test: under any interleaving of claim, store and release, the
     * storage tracks a simple per-key model — claim is a mutex (succeeds iff not
     * already claimed), a stored record stays loadable, and release clears only
     * the claim, never the record. Keys 0-2 are exercised; an untouched key must
     * stay empty. This reaches interactions the isolated tests above do not.
     */
    #[Property(runs: 300, timeoutMs: 2000)]
    public function claimStoreReleaseTrackTheModel(CommandSequence $sequence): void
    {
        $harness = new IdempotencyHarness(4);

        $kinds = [];

        foreach ($sequence->commands as $command) {
            $kinds[$command::class] = true;
        }

        // The two runs an idempotency store has to survive are a claim that is
        // never released (a crashed request holding the mutex) and a claim that
        // is released without ever storing a response. Drawn uniformly from
        // three commands, neither shape occurs.
        Classify::cover($kinds !== [] && !isset($kinds[ReleaseCommand::class]), 'nothing ever released', 15.0);
        Classify::cover($kinds !== [] && !isset($kinds[StoreCommand::class]), 'nothing ever stored', 15.0);
        Classify::cover(\count($kinds) === 3, 'all three commands present', 5.0);

        StateMachine::check($sequence, static fn(): IdempotencyHarness => $harness);

        // A key the sequence never addressed leaked no state.
        Assert::false($harness->loaded(3));
    }

    /** @return array<string, ArbitraryInterface> */
    public static function claimStoreReleaseTrackTheModelGenerators(): array
    {
        $initialModel = ['claimed' => [false, false, false], 'stored' => [false, false, false]];

        // Swarmed: each sequence may use only a subset of the three commands.
        // minLength stays at the default 0, so a subset from which nothing
        // applies yields an empty sequence rather than GenerationExhausted.
        return ['sequence' => Gen::swarm(Gen::commands($initialModel, [
            Gen::map(Gen::intBetween(0, 2), static fn(int $index): ClaimCommand => new ClaimCommand($index)),
            Gen::map(Gen::intBetween(0, 2), static fn(int $index): StoreCommand => new StoreCommand($index)),
            Gen::map(Gen::intBetween(0, 2), static fn(int $index): ReleaseCommand => new ReleaseCommand($index)),
        ]))];
    }

    /**
     * The TTL boundary is `>=`: a record stops loading exactly at `expiresAt`,
     * not a tick later. The examples pin the boundary itself; the random phase
     * sweeps the rest of the range.
     */
    #[Property(runs: 200, timeoutMs: 2000)]
    public function aRecordLoadsUntilItsTtlHasElapsed(int $ttlSeconds, int $advanceSeconds): void
    {
        Classify::cover($advanceSeconds < $ttlSeconds, 'still live', 20.0);
        Classify::cover($advanceSeconds >= $ttlSeconds, 'expired', 20.0);

        $clock = new FakeClock();
        $storage = new InMemoryIdempotencyStorage($clock);
        $key = new IdempotencyKey('key-1');

        $storage->store(IdempotencyRecord::create(
            key: $key,
            fingerprint: new IdempotencyFingerprint('hash'),
            response: new IdempotencyResponse(200, [], 'body'),
            clock: $clock,
            ttlSeconds: $ttlSeconds,
        ));
        $clock->advance($advanceSeconds);

        Assert::same($storage->load($key) instanceof IdempotencyRecord, $advanceSeconds < $ttlSeconds);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function aRecordLoadsUntilItsTtlHasElapsedGenerators(): array
    {
        return [
            'ttlSeconds' => Gen::intBetween(1, 3600),
            'advanceSeconds' => Gen::intBetween(0, 3600),
        ];
    }

    /** @return iterable<string, array{int, int}> */
    public static function aRecordLoadsUntilItsTtlHasElapsedExamples(): iterable
    {
        yield 'one second before expiry' => [60, 59];
        yield 'exactly at expiry' => [60, 60];
        yield 'one second after expiry' => [60, 61];
        yield 'shortest ttl, not yet elapsed' => [1, 0];
        yield 'shortest ttl, elapsed' => [1, 1];
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
