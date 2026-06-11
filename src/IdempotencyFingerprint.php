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
        $uri = $request->getUri();
        $stream = $request->getBody();
        $body = (string) $stream;

        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        return new self(hash(
            'sha256',
            $method . "\n" . $uri->getPath() . "\n" . $uri->getQuery() . "\n" . $body,
        ));
    }

    public function equals(self $other): bool
    {
        return $this->hash === $other->hash;
    }
}
