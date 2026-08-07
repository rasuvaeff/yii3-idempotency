<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency\Tests\Support;

use Rasuvaeff\Yii3Idempotency\IdempotencyFingerprint;
use Rasuvaeff\Yii3Idempotency\IdempotencyKey;
use Rasuvaeff\Yii3Idempotency\IdempotencyRecord;
use Rasuvaeff\Yii3Idempotency\IdempotencyResponse;
use Rasuvaeff\Yii3Idempotency\InMemoryIdempotencyStorage;
use Rasuvaeff\Yii3Idempotency\Tests\FakeClock;

/**
 * Stateful-test harness around an {@see InMemoryIdempotencyStorage}. Keys are
 * addressed by index; the clock is fixed and the TTL long, so nothing expires
 * during a run and every operation is deterministic.
 *
 * The system under test for the model-based property in
 * {@see \Rasuvaeff\Yii3Idempotency\Tests\InMemoryIdempotencyStorageTest}.
 */
final readonly class IdempotencyHarness
{
    private const int TTL_SECONDS = 3600;

    private InMemoryIdempotencyStorage $storage;
    private FakeClock $clock;
    private IdempotencyFingerprint $fingerprint;
    private IdempotencyResponse $response;

    /** @var list<IdempotencyKey> */
    private array $keys;

    public function __construct(int $keyCount)
    {
        $this->clock = new FakeClock();
        $this->storage = new InMemoryIdempotencyStorage($this->clock);
        $this->fingerprint = new IdempotencyFingerprint('fingerprint');
        $this->response = new IdempotencyResponse(200, [], 'body');
        $this->keys = array_map(
            static fn(int $i): IdempotencyKey => new IdempotencyKey('key-' . $i),
            range(0, $keyCount - 1),
        );
    }

    public function claim(int $index): bool
    {
        return $this->storage->claim($this->keys[$index], $this->fingerprint);
    }

    public function store(int $index): void
    {
        $this->storage->store(IdempotencyRecord::create(
            $this->keys[$index],
            $this->fingerprint,
            $this->response,
            $this->clock,
            self::TTL_SECONDS,
        ));
    }

    public function release(int $index): void
    {
        $this->storage->release($this->keys[$index]);
    }

    public function loaded(int $index): bool
    {
        return $this->storage->load($this->keys[$index]) instanceof IdempotencyRecord;
    }

    /**
     * Whether each of the first $count keys currently loads a record — mirrors
     * the model's "stored" flags. Pure: the fixed clock never expires anything.
     *
     * @return list<bool>
     */
    public function loadedSnapshot(int $count): array
    {
        return array_map($this->loaded(...), range(0, $count - 1));
    }
}
