<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\MiddlewareInterface;
use Rasuvaeff\Yii3Idempotency\HeaderIdempotencyKeyExtractor;
use Rasuvaeff\Yii3Idempotency\IdempotencyMiddleware;
use Rasuvaeff\Yii3Idempotency\IdempotencyPolicy;
use Rasuvaeff\Yii3Idempotency\InMemoryIdempotencyStorage;

#[CoversClass(IdempotencyMiddleware::class)]
final class IdempotencyMiddlewareTest extends TestCase
{
    private FakeClock $clock;

    private InMemoryIdempotencyStorage $storage;

    private HeaderIdempotencyKeyExtractor $extractor;

    private IdempotencyMiddleware $middleware;

    #[\Override]
    protected function setUp(): void
    {
        $this->clock = new FakeClock();
        $this->storage = new InMemoryIdempotencyStorage($this->clock);
        $this->extractor = new HeaderIdempotencyKeyExtractor();
        $this->middleware = new IdempotencyMiddleware(
            keyExtractor: $this->extractor,
            storage: $this->storage,
            responseFactory: new FakeResponseFactory(),
            clock: $this->clock,
            ttlSeconds: 3600,
        );
    }

    #[Test]
    public function implementsMiddlewareInterface(): void
    {
        $this->assertInstanceOf(MiddlewareInterface::class, $this->middleware);
    }

    #[Test]
    public function rejectsNonPositiveTtl(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new IdempotencyMiddleware(
            keyExtractor: $this->extractor,
            storage: $this->storage,
            responseFactory: new FakeResponseFactory(),
            clock: $this->clock,
            ttlSeconds: 0,
        );
    }

    #[Test]
    public function allowsTtlOfOne(): void
    {
        // 1 is valid (`< 1`, not `<= 1`): must not throw.
        $middleware = new IdempotencyMiddleware(
            keyExtractor: $this->extractor,
            storage: $this->storage,
            responseFactory: new FakeResponseFactory(),
            clock: $this->clock,
            ttlSeconds: 1,
        );

        $this->assertInstanceOf(MiddlewareInterface::class, $middleware);
    }

    #[Test]
    public function passesThroughWhenNoKeyAndPolicyPassThrough(): void
    {
        $request = new FakeRequest();
        $handler = new FakeHandler();

        $response = $this->middleware->process($request, $handler);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $handler->getCallCount());
    }

    #[Test]
    public function rejectsWhenNoKeyAndPolicyReject(): void
    {
        $middleware = new IdempotencyMiddleware(
            keyExtractor: $this->extractor,
            storage: $this->storage,
            responseFactory: new FakeResponseFactory(),
            clock: $this->clock,
            policy: IdempotencyPolicy::Reject,
        );

        $request = new FakeRequest();
        $handler = new FakeHandler();

        $response = $middleware->process($request, $handler);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(0, $handler->getCallCount());
    }

    #[Test]
    public function firstRequestPassesThrough(): void
    {
        $request = new FakeRequest(
            method: 'POST',
            path: '/api/users',
            body: '{"name":"John"}',
            headers: ['idempotency-key' => ['key-1']],
        );
        $handler = new FakeHandler();

        $response = $this->middleware->process($request, $handler);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $handler->getCallCount());
    }

    #[Test]
    public function replayReturnsCachedResponse(): void
    {
        $request = new FakeRequest(
            method: 'POST',
            path: '/api/users',
            body: '{"name":"John"}',
            headers: ['idempotency-key' => ['key-1']],
        );
        $handler = new FakeHandler(responseStatus: 201, responseBody: '{"id":1}');

        $this->middleware->process($request, $handler);

        $response = $this->middleware->process($request, $handler);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(1, $handler->getCallCount());
        $this->assertStringContainsString('{"id":1}', (string) $response->getBody());
    }

    #[Test]
    public function differentPayloadWithSameKeyReturnsUnprocessable(): void
    {
        $request1 = new FakeRequest(
            method: 'POST',
            path: '/api/users',
            body: '{"name":"John"}',
            headers: ['idempotency-key' => ['key-1']],
        );
        $request2 = new FakeRequest(
            method: 'POST',
            path: '/api/users',
            body: '{"name":"Jane"}',
            headers: ['idempotency-key' => ['key-1']],
        );
        $handler = new FakeHandler();

        $this->middleware->process($request1, $handler);
        $response = $this->middleware->process($request2, $handler);

        $this->assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function expiredRecordDoesNotReplay(): void
    {
        $middleware = new IdempotencyMiddleware(
            keyExtractor: $this->extractor,
            storage: $this->storage,
            responseFactory: new FakeResponseFactory(),
            clock: $this->clock,
            ttlSeconds: 60,
        );

        $request = new FakeRequest(
            method: 'POST',
            path: '/api/users',
            body: '{"name":"John"}',
            headers: ['idempotency-key' => ['key-1']],
        );
        $handler = new FakeHandler();

        $middleware->process($request, $handler);

        $this->clock->advance(60);

        $response = $middleware->process($request, $handler);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(2, $handler->getCallCount());
    }

    #[Test]
    public function differentKeysAreIndependent(): void
    {
        $request1 = new FakeRequest(
            method: 'POST',
            headers: ['idempotency-key' => ['key-1']],
        );
        $request2 = new FakeRequest(
            method: 'POST',
            headers: ['idempotency-key' => ['key-2']],
        );
        $handler = new FakeHandler();

        $this->middleware->process($request1, $handler);
        $response = $this->middleware->process($request2, $handler);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(2, $handler->getCallCount());
    }

    #[Test]
    public function payloadMismatchResponseContainsJsonBody(): void
    {
        $request1 = new FakeRequest(
            method: 'POST',
            body: '{"a":1}',
            headers: ['idempotency-key' => ['key-1']],
        );
        $request2 = new FakeRequest(
            method: 'POST',
            body: '{"a":2}',
            headers: ['idempotency-key' => ['key-1']],
        );
        $handler = new FakeHandler();

        $this->middleware->process($request1, $handler);
        $response = $this->middleware->process($request2, $handler);

        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('Unprocessable', (string) $response->getBody());
    }

    #[Test]
    public function replayPreservesHeaders(): void
    {
        $request = new FakeRequest(
            method: 'POST',
            headers: ['idempotency-key' => ['key-1']],
        );
        $handler = new FakeHandler();

        $this->middleware->process($request, $handler);

        $response = $this->middleware->process($request, $handler);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function replayReturnsExactBody(): void
    {
        $request = new FakeRequest(
            method: 'POST',
            headers: ['idempotency-key' => ['key-1']],
        );
        $handler = new FakeHandler(responseBody: '{"id":42,"name":"test"}');

        $this->middleware->process($request, $handler);

        $response = $this->middleware->process($request, $handler);

        $this->assertSame('{"id":42,"name":"test"}', (string) $response->getBody());
    }

    #[Test]
    public function replayPreservesCustomHeader(): void
    {
        $request = new FakeRequest(
            method: 'POST',
            headers: ['idempotency-key' => ['key-1']],
        );
        $handler = new FakeHandler(
            responseStatus: 201,
            responseHeader: 'X-Resource-Id',
            responseHeaderValue: '42',
        );

        $this->middleware->process($request, $handler);

        $response = $this->middleware->process($request, $handler);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('42', $response->getHeaderLine('X-Resource-Id'));
        $this->assertSame(1, $handler->getCallCount());
    }

    #[Test]
    public function notExpiredJustBeforeTtl(): void
    {
        $middleware = new IdempotencyMiddleware(
            keyExtractor: $this->extractor,
            storage: $this->storage,
            responseFactory: new FakeResponseFactory(),
            clock: $this->clock,
            ttlSeconds: 60,
        );

        $request = new FakeRequest(
            method: 'POST',
            headers: ['idempotency-key' => ['key-1']],
        );
        $handler = new FakeHandler(responseStatus: 201);

        $middleware->process($request, $handler);

        $this->clock->advance(59);

        $response = $middleware->process($request, $handler);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(1, $handler->getCallCount());
    }

    #[Test]
    public function storesRecordAfterFirstRequest(): void
    {
        $request = new FakeRequest(
            method: 'POST',
            body: '{"test":true}',
            headers: ['idempotency-key' => ['key-1']],
        );
        $handler = new FakeHandler(responseStatus: 201);

        $this->middleware->process($request, $handler);

        $record = $this->storage->load(new \Rasuvaeff\Yii3Idempotency\IdempotencyKey('key-1'));

        $this->assertNotNull($record);
        $this->assertSame(201, $record->response->statusCode);
    }

    #[Test]
    public function claimedKeyReturnsConflictWhileInFlight(): void
    {
        $request = new FakeRequest(
            method: 'POST',
            body: '{"a":1}',
            headers: ['idempotency-key' => ['key-1']],
        );

        $this->storage->claim(
            new \Rasuvaeff\Yii3Idempotency\IdempotencyKey('key-1'),
            \Rasuvaeff\Yii3Idempotency\IdempotencyFingerprint::fromRequest($request),
        );

        $handler = new FakeHandler();
        $response = $this->middleware->process($request, $handler);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame(0, $handler->getCallCount());
        $this->assertStringContainsString('currently being processed', (string) $response->getBody());
    }

    #[Test]
    public function serverErrorResponseIsNotStored(): void
    {
        $request = new FakeRequest(
            method: 'POST',
            headers: ['idempotency-key' => ['key-1']],
        );
        $handler = new FakeHandler(responseStatus: 500);

        $response = $this->middleware->process($request, $handler);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertNull($this->storage->load(new \Rasuvaeff\Yii3Idempotency\IdempotencyKey('key-1')));

        $retry = $this->middleware->process($request, new FakeHandler(responseStatus: 201));

        $this->assertSame(201, $retry->getStatusCode());
        $this->assertSame(1, $handler->getCallCount());
    }

    #[Test]
    public function requestBodyRemainsReadableAfterFingerprinting(): void
    {
        $request = new FakeRequest(
            method: 'POST',
            body: '{"name":"John"}',
            headers: ['idempotency-key' => ['key-1']],
        );
        $seenBody = '';
        $handler = new class ($seenBody) implements \Psr\Http\Server\RequestHandlerInterface {
            public function __construct(private string &$seenBody) {}

            #[\Override]
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                $this->seenBody = $request->getBody()->getContents();

                return new FakeResponse(200);
            }
        };

        $this->middleware->process($request, $handler);

        $this->assertSame('{"name":"John"}', $seenBody);
    }

    #[Test]
    public function sameKeyDifferentQueryReturnsUnprocessable(): void
    {
        $request1 = new FakeRequest(
            method: 'POST',
            path: '/orders',
            query: 'retry=1',
            headers: ['idempotency-key' => ['key-1']],
        );
        $request2 = new FakeRequest(
            method: 'POST',
            path: '/orders',
            query: 'retry=2',
            headers: ['idempotency-key' => ['key-1']],
        );
        $handler = new FakeHandler();

        $this->middleware->process($request1, $handler);
        $response = $this->middleware->process($request2, $handler);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(1, $handler->getCallCount());
    }

    #[Test]
    public function releasesClaimWhenHandlerThrows(): void
    {
        $request = new FakeRequest(
            method: 'POST',
            headers: ['idempotency-key' => ['key-1']],
        );
        $throwingHandler = new FakeHandler(
            throwable: new \RuntimeException('boom'),
        );

        try {
            $this->middleware->process($request, $throwingHandler);
            $this->fail('Expected RuntimeException to be thrown');
        } catch (\RuntimeException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }

        $response = $this->middleware->process($request, new FakeHandler());

        $this->assertSame(200, $response->getStatusCode());
    }
}
