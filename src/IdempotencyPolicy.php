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

    public static function fromConfigValue(string $value): self
    {
        return match (strtolower($value)) {
            'pass_through', 'passthrough' => self::PassThrough,
            'reject' => self::Reject,
            default => throw new \InvalidArgumentException(
                sprintf('Invalid idempotency policy "%s"', $value),
            ),
        };
    }
}
