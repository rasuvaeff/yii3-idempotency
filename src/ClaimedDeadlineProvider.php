<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency;

/**
 * Optional capability of an {@see IdempotencyStorage}: exposing the deadline
 * of a claim currently in flight.
 *
 * The middleware consults it when a failed `claim()` turns into the in-flight
 * 409, and carries the remaining seconds as a `Retry-After` header — so the
 * client knows how long to wait instead of polling blind. A storage that does
 * not implement it keeps the plain 409, exactly like a storage without
 * {@see ClaimedFingerprintProvider} keeps it for both conflict kinds.
 *
 * The deadline is the storage's own claim TTL boundary: the moment a
 * competitor may take the key over. Reporting it is advisory — by the time the
 * client retries, the claim may already have finished (a replay, not a
 * conflict) or been released.
 *
 * @api
 */
interface ClaimedDeadlineProvider
{
    /**
     * The deadline of the claim currently in flight under the key, or null
     * when the key carries no active claim (an unknown key, or a finished
     * record — a stored response has no claim to report, however fresh it is).
     */
    public function claimedDeadline(IdempotencyKey $key): ?\DateTimeImmutable;
}
