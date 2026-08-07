<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency\Tests;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Rasuvaeff\Yii3Idempotency\DomainFailureRenderer;

/**
 * @internal
 */
final class FakeDomainFailureRenderer implements DomainFailureRenderer
{
    private int $callCount = 0;

    public function __construct(
        private readonly int $statusCode = 422,
        private readonly bool $declines = false,
        private readonly bool $throws = false,
    ) {}

    #[\Override]
    public function render(\Throwable $failure, ServerRequestInterface $request): ?ResponseInterface
    {
        $this->callCount++;

        if ($this->throws) {
            throw new \RuntimeException('renderer is broken');
        }

        if ($this->declines) {
            return null;
        }

        $response = new FakeResponse($this->statusCode);
        $response = $response->withHeader(name: 'Content-Type', value: 'application/json');
        $response->getBody()->write(json_encode(
            ['error' => $failure->getMessage()],
            JSON_THROW_ON_ERROR,
        ));

        return $response;
    }

    public function getCallCount(): int
    {
        return $this->callCount;
    }
}
