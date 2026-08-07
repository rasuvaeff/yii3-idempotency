<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency;

/**
 * @api
 */
interface FailureClassifier
{
    public function classify(\Throwable $failure): FailureKind;
}
