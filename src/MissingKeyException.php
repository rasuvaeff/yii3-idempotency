<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency;

/**
 * Thrown when an extractor is configured to require a key that the request does
 * not carry. Raised before any claim is taken, so there is nothing to release.
 *
 * @api
 */
final class MissingKeyException extends \RuntimeException {}
