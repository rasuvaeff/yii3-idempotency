<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency;

use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * @api
 */
final class IdempotencyMiddleware implements MiddlewareInterface
{
    private const int MIN_TTL_SECONDS = 1;

    public function __construct(
        private readonly IdempotencyKeyExtractor $keyExtractor,
        private readonly IdempotencyStorage $storage,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly ClockInterface $clock,
        private readonly IdempotencyPolicy $policy = IdempotencyPolicy::PassThrough,
        private readonly int $ttlSeconds = 3600,
    ) {
        if ($ttlSeconds < self::MIN_TTL_SECONDS) {
            throw new \InvalidArgumentException('TTL seconds must be greater than 0');
        }
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $key = $this->keyExtractor->extract($request);

        if ($key === null) {
            return match ($this->policy) {
                IdempotencyPolicy::Reject => $this->responseFactory->createResponse(400),
                IdempotencyPolicy::PassThrough => $handler->handle($request),
            };
        }

        $fingerprint = IdempotencyFingerprint::fromRequest($request);

        $existing = $this->storage->load($key);

        if ($existing !== null) {
            if (!$existing->fingerprint->equals($fingerprint)) {
                return $this->conflictResponse();
            }

            return $this->replayResponse($existing->response);
        }

        if (!$this->storage->claim($key, $fingerprint)) {
            return $this->conflictResponse();
        }

        try {
            $response = $handler->handle($request);

            $record = IdempotencyRecord::create(
                key: $key,
                fingerprint: $fingerprint,
                response: $this->captureResponse($response),
                clock: $this->clock,
                ttlSeconds: $this->ttlSeconds,
            );

            $this->storage->store($record);
        } catch (\Throwable $throwable) {
            $this->storage->release($key);

            throw $throwable;
        }

        return $response;
    }

    private function conflictResponse(): ResponseInterface
    {
        $response = $this->responseFactory->createResponse(409);
        $response = $response->withHeader(name: 'Content-Type', value: 'application/json');
        $response->getBody()->write('{"error":"Conflict","message":"Idempotency key already used with different payload"}');

        return $response;
    }

    private function replayResponse(IdempotencyResponse $captured): ResponseInterface
    {
        $response = $this->responseFactory->createResponse($captured->statusCode);

        foreach ($captured->headers as $name => $values) {
            $response = $response->withHeader(name: $name, value: $values);
        }

        $response->getBody()->write($captured->body);

        return $response;
    }

    /**
     * @return array<string, list<string>>
     */
    private function captureHeaders(ResponseInterface $response): array
    {
        /** @var array<string, list<string>> $headers */
        $headers = [];

        foreach ($response->getHeaders() as $name => $values) {
            /** @var list<string> $values */
            $headers[(string) $name] = $values;
        }

        return $headers;
    }

    private function captureResponse(ResponseInterface $response): IdempotencyResponse
    {
        return new IdempotencyResponse(
            statusCode: $response->getStatusCode(),
            headers: $this->captureHeaders($response),
            body: (string) $response->getBody(),
        );
    }
}
