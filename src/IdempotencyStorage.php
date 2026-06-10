<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency;

/**
 * @api
 */
interface IdempotencyStorage
{
    public function load(IdempotencyKey $key): ?IdempotencyRecord;

    public function claim(IdempotencyKey $key, IdempotencyFingerprint $fingerprint): bool;

    public function store(IdempotencyRecord $record): void;

    public function release(IdempotencyKey $key): void;
}
