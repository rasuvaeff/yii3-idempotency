<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3Idempotency\IdempotencyResponse;

#[CoversClass(IdempotencyResponse::class)]
final class IdempotencyResponseTest extends TestCase
{
    #[Test]
    public function storesValues(): void
    {
        $response = new IdempotencyResponse(
            statusCode: 201,
            headers: ['content-type' => ['application/json']],
            body: '{"id":1}',
        );

        $this->assertSame(201, $response->statusCode);
        $this->assertSame(['content-type' => ['application/json']], $response->headers);
        $this->assertSame('{"id":1}', $response->body);
    }

    #[Test]
    public function defaultsToEmptyHeaders(): void
    {
        $response = new IdempotencyResponse(statusCode: 200, headers: [], body: '');

        $this->assertSame([], $response->headers);
    }
}
