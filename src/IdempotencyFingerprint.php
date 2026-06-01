<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency;

use Psr\Http\Message\ServerRequestInterface;

/**
 * @api
 */
final readonly class IdempotencyFingerprint
{
    public string $hash;

    public function __construct(string $hash)
    {
        $this->hash = $hash;
    }

    public static function fromRequest(ServerRequestInterface $request): self
    {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();
        $body = (string) $request->getBody();

        return new self(hash('sha256', $method . "\n" . $path . "\n" . $body));
    }

    public function equals(self $other): bool
    {
        return $this->hash === $other->hash;
    }
}
