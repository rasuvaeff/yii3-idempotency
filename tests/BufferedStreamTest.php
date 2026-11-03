<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency\Tests;

use Rasuvaeff\Yii3Idempotency\BufferedStream;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(BufferedStream::class)]
final class BufferedStreamTest
{
    public function startsAtTheBeginning(): void
    {
        $stream = new BufferedStream('abcdef');

        Assert::same($stream->tell(), 0);
        Assert::same($stream->eof(), expected: false);
        Assert::same($stream->getSize(), 6);
    }

    public function reportsItsCapabilities(): void
    {
        $stream = new BufferedStream('abc');

        Assert::same($stream->isSeekable(), expected: true);
        Assert::same($stream->isReadable(), expected: true);
        Assert::same($stream->isWritable(), expected: false);
        Assert::null($stream->getMetadata());
        Assert::null($stream->detach());
    }

    public function toStringReturnsEverythingAndMovesToTheEnd(): void
    {
        $stream = new BufferedStream('abcdef');
        $stream->seek(2);

        Assert::same((string) $stream, 'abcdef');
        Assert::same($stream->tell(), 6);
    }

    public function eofIsReachedExactlyAtTheEnd(): void
    {
        $stream = new BufferedStream('abc');

        $stream->read(2);
        Assert::same($stream->eof(), expected: false);

        $stream->read(1);
        Assert::same($stream->eof(), expected: true);
    }

    public function emptyBodyIsImmediatelyAtEof(): void
    {
        Assert::same((new BufferedStream(''))->eof(), expected: true);
    }

    public function readReturnsChunksAndAdvancesByTheirLength(): void
    {
        $stream = new BufferedStream('abcdef');

        Assert::same($stream->read(4), 'abcd');
        Assert::same($stream->tell(), 4);
        Assert::same($stream->read(10), 'ef');
        Assert::same($stream->tell(), 6);
        Assert::same($stream->read(1), '');
    }

    public function getContentsReturnsTheRestFromTheCurrentPosition(): void
    {
        $stream = new BufferedStream('abcdef');
        $stream->read(2);

        Assert::same($stream->getContents(), 'cdef');
        Assert::same($stream->tell(), 6);
        Assert::same($stream->getContents(), '');
    }

    public function seekSetIsAbsolute(): void
    {
        $stream = new BufferedStream('abcdef');
        $stream->read(3);

        $stream->seek(2);

        Assert::same($stream->tell(), 2);
        Assert::same($stream->read(1), 'c');
    }

    public function seekCurIsRelativeToThePosition(): void
    {
        $stream = new BufferedStream('abcdef');
        $stream->read(3);

        $stream->seek(1, SEEK_CUR);
        Assert::same($stream->tell(), 4);

        $stream->seek(-2, SEEK_CUR);
        Assert::same($stream->tell(), 2);
    }

    public function seekEndIsRelativeToTheLength(): void
    {
        $stream = new BufferedStream('abcdef');
        $stream->read(2);

        $stream->seek(-2, SEEK_END);

        Assert::same($stream->tell(), 4);
        Assert::same($stream->read(5), 'ef');
    }

    public function seekNeverGoesBeforeTheStart(): void
    {
        $stream = new BufferedStream('abcdef');

        $stream->seek(-5);
        Assert::same($stream->tell(), 0);

        $stream->seek(-100, SEEK_END);
        Assert::same($stream->tell(), 0);
    }

    public function rewindGoesBackToTheStart(): void
    {
        $stream = new BufferedStream('abc');
        $stream->getContents();

        $stream->rewind();

        Assert::same($stream->tell(), 0);
        Assert::same($stream->getContents(), 'abc');
    }

    public function writingIsRejected(): void
    {
        $stream = new BufferedStream('abc');

        try {
            $stream->write('x');
            Assert::fail('Expected \RuntimeException');
        } catch (\RuntimeException $e) {
            Assert::same($e->getMessage(), 'Cannot write to a buffered body');
        }
    }

    public function closeKeepsTheContentAvailable(): void
    {
        $stream = new BufferedStream('abc');

        $stream->close();

        Assert::same((string) $stream, 'abc');
    }
}
