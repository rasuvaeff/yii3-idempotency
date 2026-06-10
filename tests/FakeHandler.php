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

    public function __construct(
        private readonly int $responseStatus = 200,
        private readonly string $responseBody = '{"ok":true}',
        private readonly string $responseHeader = '',
        private readonly string $responseHeaderValue = '',
        private readonly ?\Throwable $throwable = null,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->callCount++;

        if ($this->throwable !== null) {
            throw $this->throwable;
        }

        $response = new FakeResponse($this->responseStatus);

        if ($this->responseHeader !== '') {
            $response = $response->withHeader(
                name: $this->responseHeader,
                value: $this->responseHeaderValue,
            );
        }

        $response->getBody()->write($this->responseBody);

        return $response;
    }

    public function getCallCount(): int
    {
        return $this->callCount;
    }
}
