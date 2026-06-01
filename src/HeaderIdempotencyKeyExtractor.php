<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency;

use Psr\Http\Message\ServerRequestInterface;

/**
 * @api
 */
final class HeaderIdempotencyKeyExtractor implements IdempotencyKeyExtractor
{
    public function __construct(
        private readonly string $headerName = 'Idempotency-Key',
    ) {}

    #[\Override]
    public function extract(ServerRequestInterface $request): ?IdempotencyKey
    {
        $values = $request->getHeader($this->headerName);

        if ($values === []) {
            return null;
        }

        $value = $values[0];

        if ($value === '') {
            return null;
        }

        return new IdempotencyKey($value);
    }
}
