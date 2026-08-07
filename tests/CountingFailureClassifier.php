<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency\Tests;

use Rasuvaeff\Yii3Idempotency\FailureClassifier;
use Rasuvaeff\Yii3Idempotency\FailureKind;

/**
 * @internal
 */
final class CountingFailureClassifier implements FailureClassifier
{
    private int $callCount = 0;

    public function __construct(
        private readonly FailureKind $kind = FailureKind::Domain,
    ) {}

    #[\Override]
    public function classify(\Throwable $failure): FailureKind
    {
        $this->callCount++;

        return $this->kind;
    }

    public function getCallCount(): int
    {
        return $this->callCount;
    }
}
