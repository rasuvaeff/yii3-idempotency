<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency;

/**
 * @api
 */
final readonly class IdempotencyKey
{
    private const int MIN_LENGTH = 1;

    private const int MAX_LENGTH = 255;

    private const string PATTERN = '/^[A-Za-z0-9._-]+$/';

    public string $value;

    public function __construct(string $value)
    {
        $length = strlen($value);

        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            throw new \InvalidArgumentException(
                'Idempotency key must be between 1 and 255 characters',
            );
        }

        if (!preg_match(self::PATTERN, $value)) {
            throw new \InvalidArgumentException(
                'Idempotency key contains invalid characters',
            );
        }

        $this->value = $value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
