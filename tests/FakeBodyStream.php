<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency\Tests;

use Psr\Http\Message\StreamInterface;

/**
 * @internal
 */
final class FakeBodyStream implements StreamInterface
{
    public function __construct(
        private readonly string $data = '',
    ) {}

    public function __toString(): string
    {
        return $this->data;
    }

    #[\Override]
    public function close(): void {}

    #[\Override]
    public function detach(): null
    {
        return null;
    }

    #[\Override]
    public function getSize(): int
    {
        return strlen($this->data);
    }

    #[\Override]
    public function tell(): int
    {
        return 0;
    }

    #[\Override]
    public function eof(): bool
    {
        return true;
    }

    #[\Override]
    public function isSeekable(): bool
    {
        return true;
    }

    #[\Override]
    public function seek(int $offset, int $whence = SEEK_SET): void {}

    #[\Override]
    public function rewind(): void {}

    #[\Override]
    public function isWritable(): bool
    {
        return false;
    }

    #[\Override]
    public function write(string $string): int
    {
        return 0;
    }

    #[\Override]
    public function isReadable(): bool
    {
        return true;
    }

    #[\Override]
    public function read(int $length): string
    {
        return '';
    }

    #[\Override]
    public function getContents(): string
    {
        return $this->data;
    }

    #[\Override]
    public function getMetadata(?string $key = null): ?array
    {
        return null;
    }
}
