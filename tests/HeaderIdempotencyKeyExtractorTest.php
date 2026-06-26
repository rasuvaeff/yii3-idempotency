<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency\Tests;

use Rasuvaeff\Yii3Idempotency\HeaderIdempotencyKeyExtractor;
use Rasuvaeff\Yii3Idempotency\IdempotencyKey;
use Rasuvaeff\Yii3Idempotency\IdempotencyKeyExtractor;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(HeaderIdempotencyKeyExtractor::class)]
final class HeaderIdempotencyKeyExtractorTest
{
    public function extractsKeyFromHeader(): void
    {
        $extractor = new HeaderIdempotencyKeyExtractor();
        $request = new FakeRequest(headers: ['idempotency-key' => ['test-key-1']]);

        $key = $extractor->extract($request);

        Assert::notNull($key);
        Assert::same($key->value, 'test-key-1');
    }

    public function returnsNullWhenHeaderMissing(): void
    {
        $extractor = new HeaderIdempotencyKeyExtractor();
        $request = new FakeRequest();

        Assert::null($extractor->extract($request));
    }

    public function returnsNullWhenHeaderEmpty(): void
    {
        $extractor = new HeaderIdempotencyKeyExtractor();
        $request = new FakeRequest(headers: ['idempotency-key' => ['']]);

        Assert::null($extractor->extract($request));
    }

    public function usesCustomHeaderName(): void
    {
        $extractor = new HeaderIdempotencyKeyExtractor(headerName: 'X-Idempotency-Key');
        $request = new FakeRequest(headers: ['x-idempotency-key' => ['custom-key']]);

        $key = $extractor->extract($request);

        Assert::notNull($key);
        Assert::same($key->value, 'custom-key');
    }

    public function implementsInterface(): void
    {
        $extractor = new HeaderIdempotencyKeyExtractor();

        Assert::instanceOf($extractor, IdempotencyKeyExtractor::class);
    }

    public function returnsIdempotencyKeyInstance(): void
    {
        $extractor = new HeaderIdempotencyKeyExtractor();
        $request = new FakeRequest(headers: ['idempotency-key' => ['my-key']]);

        $key = $extractor->extract($request);

        Assert::instanceOf($key, IdempotencyKey::class);
    }
}
