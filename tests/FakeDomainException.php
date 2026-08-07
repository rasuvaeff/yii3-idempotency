<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency\Tests;

/**
 * A deterministic business outcome — the same request always fails the same way.
 *
 * @internal
 */
final class FakeDomainException extends \RuntimeException {}
