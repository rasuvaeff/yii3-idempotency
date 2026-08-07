<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency\Tests;

use Rasuvaeff\Yii3Idempotency\RetryableFailure;

/**
 * @internal
 */
final class FakeRetryableException extends \RuntimeException implements RetryableFailure {}
