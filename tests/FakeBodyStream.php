<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency\Tests;

use Psr\Http\Message\StreamInterface;

/**
 * @internal
 */
final class FakeBodyStream implements StreamInterface
{
    private int $position = 0;

    public function __construct(
        private readonly string $data = '',
    ) {}

    public function __toString(): string
    {
        $this->position = strlen($this->data);

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
        return $this->position;
    }

    #[\Override]
    public function eof(): bool
    {
        return $this->position >= strlen($this->data);
    }

    #[\Override]
    public function isSeekable(): bool
    {
        return true;
    }

    #[\Override]
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->position = $offset;
    }

    #[\Override]
    public function rewind(): void
    {
        $this->position = 0;
    }

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
        $chunk = substr($this->data, $this->position, $length);
        $this->position += strlen($chunk);

        return $chunk;
    }

    #[\Override]
    public function getContents(): string
    {
        $contents = substr($this->data, $this->position);
        $this->position = strlen($this->data);

        return $contents;
    }

    #[\Override]
    public function getMetadata(?string $key = null): ?array
    {
        return null;
    }
}
