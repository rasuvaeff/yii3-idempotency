<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency\Tests;

/**
 * @internal
 */
final readonly class StringableIdentity implements \Stringable
{
    public function __construct(private string $value) {}

    #[\Override]
    public function __toString(): string
    {
        return $this->value;
    }
}
