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

    private const int SUCCESS_STATUS_MIN = 200;

    private const int SUCCESS_STATUS_MAX_EXCLUSIVE = 300;

    /**
     * @var list<string>
     */
    private readonly array $methods;

    /**
     * @param list<string> $methods HTTP methods idempotency applies to; others pass through untouched
     */
    public function __construct(
        private readonly IdempotencyKeyExtractor $keyExtractor,
        private readonly IdempotencyStorage $storage,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly ClockInterface $clock,
        private readonly IdempotencyPolicy $policy = IdempotencyPolicy::PassThrough,
        private readonly int $ttlSeconds = 3600,
        array $methods = ['POST', 'PUT', 'PATCH'],
    ) {
        if ($ttlSeconds < self::MIN_TTL_SECONDS) {
            throw new \InvalidArgumentException('TTL seconds must be greater than 0');
        }

        $this->methods = array_map(strtoupper(...), $methods);
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!\in_array(strtoupper($request->getMethod()), $this->methods, true)) {
            return $handler->handle($request);
        }

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
                return $this->payloadMismatchResponse();
            }

            return $this->replayResponse($existing->response);
        }

        if (!$this->storage->claim($key, $fingerprint)) {
            return $this->inProgressResponse();
        }

        try {
            $response = $handler->handle($request);

            $status = $response->getStatusCode();

            // Only successful (2xx) responses are cached. Anything else — redirects,
            // client errors (incl. retryable 409/423/429), server errors — releases
            // the claim so the request can be retried under the same key.
            if ($status < self::SUCCESS_STATUS_MIN || $status >= self::SUCCESS_STATUS_MAX_EXCLUSIVE) {
                $this->storage->release($key);

                return $response;
            }

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

    private function payloadMismatchResponse(): ResponseInterface
    {
        return $this->jsonErrorResponse(
            statusCode: 422,
            body: '{"error":"Unprocessable Content","message":"Idempotency key already used with different payload"}',
        );
    }

    private function inProgressResponse(): ResponseInterface
    {
        return $this->jsonErrorResponse(
            statusCode: 409,
            body: '{"error":"Conflict","message":"Request with this idempotency key is currently being processed"}',
        );
    }

    private function jsonErrorResponse(int $statusCode, string $body): ResponseInterface
    {
        $response = $this->responseFactory->createResponse($statusCode);
        $response = $response->withHeader(name: 'Content-Type', value: 'application/json');
        $response->getBody()->write($body);
        $response->getBody()->rewind();

        return $response;
    }

    private function replayResponse(IdempotencyResponse $captured): ResponseInterface
    {
        $response = $this->responseFactory->createResponse($captured->statusCode);

        foreach ($captured->headers as $name => $values) {
            $response = $response->withHeader(name: $name, value: $values);
        }

        $response->getBody()->write($captured->body);
        $response->getBody()->rewind();

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
        $stream = $response->getBody();
        $body = (string) $stream;

        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        return new IdempotencyResponse(
            statusCode: $response->getStatusCode(),
            headers: $this->captureHeaders($response),
            body: $body,
        );
    }
}
