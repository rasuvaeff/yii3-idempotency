<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency;

use Psr\Http\Message\StreamInterface;

/**
 * A seekable in-memory copy of a stream body that has already been drained.
 *
 * PSR-7 messages are immutable, so a drained non-seekable body cannot be
 * rewound — the only way to keep the content available to the next consumer
 * is to put it back with a fresh seekable stream (`withBody()`).
 *
 * @internal
 */
final class BufferedStream implements StreamInterface
{
    private int $position = 0;

    public function __construct(private readonly string $contents) {}

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
        if ($whence === SEEK_END) {
            $offset += strlen($this->contents);
        } elseif ($whence === SEEK_CUR) {
            $offset += $this->position;
        }

        $this->position = max(0, $offset);
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
    public function write(string $string): never
    {
        throw new \RuntimeException('Cannot write to a buffered body');
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
    public function getMetadata(?string $key = null): null
    {
        return null;
    }
}
