<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency;

/**
 * Optional capability of an {@see IdempotencyStorage}: exposing the fingerprint
 * an in-flight claim was taken with.
 *
 * The middleware consults it after a failed `claim()` to tell an in-flight
 * duplicate (409, retry later) from a key reused with a different payload while
 * the original request is still being processed (422, retrying can never
 * succeed). A storage that does not implement it keeps the plain 409 for both.
 *
 * @api
 */
interface ClaimedFingerprintProvider
{
    /**
     * The fingerprint of the claim currently in flight under the key, or null
     * when the key carries no active claim (an unknown key, or a finished
     * record).
     */
    public function claimedFingerprint(IdempotencyKey $key): ?IdempotencyFingerprint;
}
