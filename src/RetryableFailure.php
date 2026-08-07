<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency;

/**
 * Marker for exceptions that a retry may resolve. {@see DefaultFailureClassifier}
 * classifies them as {@see FailureKind::Infrastructure} instead of a domain
 * outcome, so the claim is released and the handler runs again.
 *
 * @api
 */
interface RetryableFailure extends \Throwable {}
