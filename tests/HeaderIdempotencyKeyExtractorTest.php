<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3Idempotency\HeaderIdempotencyKeyExtractor;
use Rasuvaeff\Yii3Idempotency\IdempotencyKey;
use Rasuvaeff\Yii3Idempotency\IdempotencyKeyExtractor;

#[CoversClass(HeaderIdempotencyKeyExtractor::class)]
final class HeaderIdempotencyKeyExtractorTest extends TestCase
{
    #[Test]
    public function extractsKeyFromHeader(): void
    {
        $extractor = new HeaderIdempotencyKeyExtractor();
        $request = new FakeRequest(headers: ['idempotency-key' => ['test-key-1']]);

        $key = $extractor->extract($request);

        $this->assertNotNull($key);
        $this->assertSame('test-key-1', $key->value);
    }

    #[Test]
    public function returnsNullWhenHeaderMissing(): void
    {
        $extractor = new HeaderIdempotencyKeyExtractor();
        $request = new FakeRequest();

        $this->assertNull($extractor->extract($request));
    }

    #[Test]
    public function returnsNullWhenHeaderEmpty(): void
    {
        $extractor = new HeaderIdempotencyKeyExtractor();
        $request = new FakeRequest(headers: ['idempotency-key' => ['']]);

        $this->assertNull($extractor->extract($request));
    }

    #[Test]
    public function usesCustomHeaderName(): void
    {
        $extractor = new HeaderIdempotencyKeyExtractor(headerName: 'X-Idempotency-Key');
        $request = new FakeRequest(headers: ['x-idempotency-key' => ['custom-key']]);

        $key = $extractor->extract($request);

        $this->assertNotNull($key);
        $this->assertSame('custom-key', $key->value);
    }

    #[Test]
    public function implementsInterface(): void
    {
        $extractor = new HeaderIdempotencyKeyExtractor();

        $this->assertInstanceOf(IdempotencyKeyExtractor::class, $extractor);
    }

    #[Test]
    public function returnsIdempotencyKeyInstance(): void
    {
        $extractor = new HeaderIdempotencyKeyExtractor();
        $request = new FakeRequest(headers: ['idempotency-key' => ['my-key']]);

        $key = $extractor->extract($request);

        $this->assertInstanceOf(IdempotencyKey::class, $key);
    }
}
