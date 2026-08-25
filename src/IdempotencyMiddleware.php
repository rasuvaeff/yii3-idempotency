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
final readonly class IdempotencyMiddleware implements MiddlewareInterface
{
    private const int MIN_TTL_SECONDS = 1;

    private const int SUCCESS_STATUS_MIN = 200;

    private const int SUCCESS_STATUS_MAX_EXCLUSIVE = 300;

    /**
     * Headers that describe the original response or connection and must never
     * be persisted and handed back on a replay. `Set-Cookie` above all: a
     * session identifier does not belong in a storage row for the whole TTL,
     * and a replayed one is stale at best.
     *
     * Always applied. A caller-supplied list only extends it, so no
     * configuration can bring `Set-Cookie` back into storage.
     *
     * @var list<non-empty-string>
     */
    private const array DEFAULT_EXCLUDED_RESPONSE_HEADERS = [
        'set-cookie',
        'date',
        'connection',
        'keep-alive',
        'transfer-encoding',
        'te',
        'trailer',
        'upgrade',
        'proxy-authenticate',
        'proxy-authorization',
    ];

    /**
     * @var list<string>
     */
    private array $methods;

    /**
     * @var list<string>
     */
    private array $excludedResponseHeaders;

    private FailureClassifier $failureClassifier;

    /**
     * @param IdempotencyScopeResolver $scopeResolver partitions the keyspace; there is no default because
     *                                                every safe choice depends on the application. Use
     *                                                {@see RequestAttributeScopeResolver} for a multi-client
     *                                                API, {@see SharedKeyspaceScopeResolver} only when a
     *                                                single principal can reach this middleware
     * @param list<string> $methods HTTP methods idempotency applies to; others pass through untouched
     * @param DomainFailureRenderer|null $domainFailureRenderer renders domain failures so they can be cached;
     *                                                          `null` keeps every thrown failure retryable
     * @param FailureClassifier|null $failureClassifier defaults to {@see DefaultFailureClassifier}
     * @param list<string> $additionalExcludedResponseHeaders further response headers never captured nor
     *                                                        replayed; they are *added* to the built-in
     *                                                        list, which cannot be switched off — hiding
     *                                                        one header of your own must never re-enable
     *                                                        storing and replaying `Set-Cookie`
     */
    public function __construct(
        private IdempotencyKeyExtractor $keyExtractor,
        private IdempotencyStorage $storage,
        private ResponseFactoryInterface $responseFactory,
        private ClockInterface $clock,
        private IdempotencyScopeResolver $scopeResolver,
        private IdempotencyPolicy $policy = IdempotencyPolicy::PassThrough,
        private int $ttlSeconds = 3600,
        array $methods = ['POST', 'PUT', 'PATCH'],
        private ?DomainFailureRenderer $domainFailureRenderer = null,
        ?FailureClassifier $failureClassifier = null,
        array $additionalExcludedResponseHeaders = [],
    ) {
        if ($ttlSeconds < self::MIN_TTL_SECONDS) {
            throw new \InvalidArgumentException('TTL seconds must be greater than 0');
        }

        $this->methods = array_map(strtoupper(...), $methods);
        $this->failureClassifier = $failureClassifier ?? new DefaultFailureClassifier();

        $this->excludedResponseHeaders = array_merge(
            self::DEFAULT_EXCLUDED_RESPONSE_HEADERS,
            array_map(strtolower(...), $additionalExcludedResponseHeaders),
        );
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!\in_array(strtoupper($request->getMethod()), $this->methods, strict: true)) {
            return $handler->handle($request);
        }

        // The key comes straight off an untrusted request, so a value that
        // IdempotencyKey rejects is a client error, not a server fault.
        // MissingKeyException is a RuntimeException and keeps propagating: a
        // `required: true` extractor raising it is a deliberate contract.
        try {
            $key = $this->keyExtractor->extract($request);
        } catch (\InvalidArgumentException) {
            return $this->malformedKeyResponse();
        }

        if (!$key instanceof IdempotencyKey) {
            return match ($this->policy) {
                IdempotencyPolicy::Reject => $this->responseFactory->createResponse(400),
                IdempotencyPolicy::PassThrough => $handler->handle($request),
            };
        }

        // Deliberately outside the catch above: the scope comes from the
        // application (a request attribute it populates), so a failure here is
        // a deployment error that must not be reported as a bad request.
        $key = $this->scopeResolver->resolve($request)->apply($key);

        // A non-seekable request body cannot be rewound after the fingerprint
        // drains it — the handler would receive an empty stream. Put the
        // content back into the message first, so both the fingerprint and
        // the handler see an intact body.
        $request = $this->withIntactRequestBody($request);

        $fingerprint = IdempotencyFingerprint::fromRequest($request);

        $existing = $this->storage->load($key);

        if ($existing instanceof IdempotencyRecord) {
            if (!$existing->fingerprint->equals($fingerprint)) {
                return $this->payloadMismatchResponse();
            }

            return $this->replayResponse($existing->response);
        }

        if (!$this->storage->claim($key, $fingerprint)) {
            return $this->inProgressResponse();
        }

        // Only what the handler itself throws is a candidate for classification;
        // a storage failure below must never be mistaken for a domain outcome.
        try {
            $response = $handler->handle($request);
        } catch (\Throwable $throwable) {
            return $this->handleThrownFailure(
                key: $key,
                fingerprint: $fingerprint,
                request: $request,
                throwable: $throwable,
            );
        }

        $status = $response->getStatusCode();

        // Only successful (2xx) responses are cached. Anything else — redirects,
        // client errors (incl. retryable 409/423/429), server errors — releases
        // the claim so the request can be retried under the same key.
        if ($status < self::SUCCESS_STATUS_MIN || $status >= self::SUCCESS_STATUS_MAX_EXCLUSIVE) {
            $this->storage->release($key);

            return $response;
        }

        try {
            [$captured, $response] = $this->captureResponse($response);

            $this->storage->store(IdempotencyRecord::create(
                key: $key,
                fingerprint: $fingerprint,
                response: $captured,
                clock: $this->clock,
                ttlSeconds: $this->ttlSeconds,
            ));
        } catch (\Throwable $throwable) {
            $this->releaseQuietly($key);

            throw $throwable;
        }

        return $response;
    }

    /**
     * Resolves a throwable raised by the handler: either a cached domain failure
     * to return, or a released claim and the original throwable rethrown.
     */
    private function handleThrownFailure(
        IdempotencyKey $key,
        IdempotencyFingerprint $fingerprint,
        ServerRequestInterface $request,
        \Throwable $throwable,
    ): ResponseInterface {
        try {
            $rendered = $this->cacheDomainFailure(
                key: $key,
                fingerprint: $fingerprint,
                request: $request,
                throwable: $throwable,
            );

            if ($rendered instanceof ResponseInterface) {
                return $rendered;
            }
        } catch (\Throwable) {
            // A classifier, renderer or storage that fails here must not strand
            // the claim — that would answer every later request with 409 until
            // the claim TTL expires, and forever in storage without one. Fall
            // through to the retryable path, which is exactly what happens when
            // no renderer is configured at all.
        }

        $this->releaseQuietly($key);

        throw $throwable;
    }

    /**
     * Releases a claim on a path that is already unwinding a failure.
     *
     * A cleanup that fails must not replace the throwable the caller needs to
     * see: the claim is stuck either way until its TTL expires, and swapping in
     * a storage error would only hide why the request failed.
     */
    private function releaseQuietly(IdempotencyKey $key): void
    {
        try {
            $this->storage->release($key);
        } catch (\Throwable) {
            // deliberately swallowed — see above
        }
    }

    /**
     * Caches a deterministic domain failure as an ordinary response snapshot, so
     * a retry under the same key replays it instead of re-running the handler.
     *
     * Returns `null` when the failure stays retryable — no renderer configured,
     * a non-domain kind, or a renderer that declined it.
     */
    private function cacheDomainFailure(
        IdempotencyKey $key,
        IdempotencyFingerprint $fingerprint,
        ServerRequestInterface $request,
        \Throwable $throwable,
    ): ?ResponseInterface {
        if (!$this->domainFailureRenderer instanceof DomainFailureRenderer) {
            return null;
        }

        if ($this->failureClassifier->classify($throwable) !== FailureKind::Domain) {
            return null;
        }

        $response = $this->domainFailureRenderer->render($throwable, $request);

        if (!$response instanceof ResponseInterface) {
            return null;
        }

        [$captured, $response] = $this->captureResponse($response);

        $this->storage->store(IdempotencyRecord::create(
            key: $key,
            fingerprint: $fingerprint,
            response: $captured,
            clock: $this->clock,
            ttlSeconds: $this->ttlSeconds,
        ));

        return $response;
    }

    private function payloadMismatchResponse(): ResponseInterface
    {
        return $this->jsonErrorResponse(
            statusCode: 422,
            body: '{"error":"Unprocessable Content","message":"Idempotency key already used with different payload"}',
        );
    }

    private function malformedKeyResponse(): ResponseInterface
    {
        return $this->jsonErrorResponse(
            statusCode: 400,
            body: '{"error":"Bad Request","message":"Idempotency key has an invalid format"}',
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
            $name = (string) $name;

            if (\in_array(strtolower($name), $this->excludedResponseHeaders, strict: true)) {
                continue;
            }

            /** @var list<string> $values */
            $headers[$name] = $values;
        }

        return $headers;
    }

    private function withIntactRequestBody(ServerRequestInterface $request): ServerRequestInterface
    {
        $stream = $request->getBody();

        // A seekable body is left alone: the fingerprint drains and rewinds it.
        if ($stream->isSeekable()) {
            return $request;
        }

        return $request->withBody(new BufferedStream((string) $stream));
    }

    /**
     * Drains the response body into a replayable snapshot and returns it
     * together with the message to hand back to the client.
     *
     * A non-seekable body cannot be rewound after draining, so the returned
     * message gets a fresh seekable stream with the content — otherwise the
     * first caller receives an empty body while every replay gets the full one.
     *
     * @return array{IdempotencyResponse, ResponseInterface}
     */
    private function captureResponse(ResponseInterface $response): array
    {
        $stream = $response->getBody();
        $body = (string) $stream;

        if ($stream->isSeekable()) {
            $stream->rewind();
        } else {
            $response = $response->withBody(new BufferedStream($body));
        }

        return [
            new IdempotencyResponse(
                statusCode: $response->getStatusCode(),
                headers: $this->captureHeaders($response),
                body: $body,
            ),
            $response,
        ];
    }
}
