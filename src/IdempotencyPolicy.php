<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency;

/**
 * @api
 */
enum IdempotencyPolicy
{
    case PassThrough;
    case Reject;
}
