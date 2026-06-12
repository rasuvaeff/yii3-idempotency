<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency\Tests;

use Psr\Http\Message\StreamInterface;

/**
 * Position-aware in-memory stream: `write()` advances the cursor to the end, so
 * `getContents()`/`read()` return nothing until the stream is rewound — exactly
 * how a real PSR-7 body behaves. Lets tests detect a missing `rewind()`.
 *
 * @internal
 */
final class FakeStream implements StreamInterface
{
    private string $contents = '';

    private int $position = 0;

    public function __toString(): string
    {
        $this->position = strlen($this->contents);

        return $this->contents;
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
        return strlen($this->contents);
    }

    #[\Override]
    public function tell(): int
    {
        return $this->position;
    }

    #[\Override]
    public function eof(): bool
    {
        return $this->position >= strlen($this->contents);
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
        return true;
    }

    #[\Override]
    public function write(string $string): int
    {
        $this->contents .= $string;
        $this->position = strlen($this->contents);

        return strlen($string);
    }

    #[\Override]
    public function isReadable(): bool
    {
        return true;
    }

    #[\Override]
    public function read(int $length): string
    {
        $chunk = substr($this->contents, $this->position, $length);
        $this->position += strlen($chunk);

        return $chunk;
    }

    #[\Override]
    public function getContents(): string
    {
        $contents = substr($this->contents, $this->position);
        $this->position = strlen($this->contents);

        return $contents;
    }

    #[\Override]
    public function getMetadata(?string $key = null): ?array
    {
        return null;
    }
}
