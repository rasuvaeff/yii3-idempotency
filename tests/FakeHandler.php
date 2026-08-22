<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency\Tests;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * @internal
 */
final class FakeHandler implements RequestHandlerInterface
{
    private int $callCount = 0;

    /**
     * @param array<string, string> $responseHeaders applied in order, after $responseHeader
     */
    public function __construct(
        private readonly int $responseStatus = 200,
        private readonly string $responseBody = '{"ok":true}',
        private readonly string $responseHeader = '',
        private readonly string $responseHeaderValue = '',
        private readonly ?\Throwable $throwable = null,
        private readonly array $responseHeaders = [],
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->callCount++;

        if ($this->throwable instanceof \Throwable) {
            throw $this->throwable;
        }

        $response = new FakeResponse($this->responseStatus);

        if ($this->responseHeader !== '') {
            $response = $response->withHeader(
                name: $this->responseHeader,
                value: $this->responseHeaderValue,
            );
        }

        foreach ($this->responseHeaders as $name => $value) {
            $response = $response->withHeader(name: $name, value: $value);
        }

        $response->getBody()->write($this->responseBody);

        return $response;
    }

    public function getCallCount(): int
    {
        return $this->callCount;
    }
}
