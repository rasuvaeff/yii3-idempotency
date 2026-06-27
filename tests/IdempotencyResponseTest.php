<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency\Tests;

use Rasuvaeff\Yii3Idempotency\IdempotencyResponse;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(IdempotencyResponse::class)]
final class IdempotencyResponseTest
{
    public function storesValues(): void
    {
        $response = new IdempotencyResponse(
            statusCode: 201,
            headers: ['content-type' => ['application/json']],
            body: '{"id":1}',
        );

        Assert::same($response->statusCode, 201);
        Assert::same($response->headers, ['content-type' => ['application/json']]);
        Assert::same($response->body, '{"id":1}');
    }

    public function defaultsToEmptyHeaders(): void
    {
        $response = new IdempotencyResponse(statusCode: 200, headers: [], body: '');

        Assert::same($response->headers, []);
    }
}
