<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency;

/**
 * @api
 */
final readonly class IdempotencyResponse
{
    /**
     * @param array<string, list<string>> $headers
     */
    public function __construct(
        public int $statusCode,
        public array $headers,
        public string $body,
    ) {}
}
