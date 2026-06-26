<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency\Tests;

use Psr\Http\Server\MiddlewareInterface;
use Rasuvaeff\Yii3Idempotency\HeaderIdempotencyKeyExtractor;
use Rasuvaeff\Yii3Idempotency\IdempotencyKey;
use Rasuvaeff\Yii3Idempotency\IdempotencyMiddleware;
use Rasuvaeff\Yii3Idempotency\IdempotencyPolicy;
use Rasuvaeff\Yii3Idempotency\InMemoryIdempotencyStorage;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(IdempotencyMiddleware::class)]
final class IdempotencyMiddlewareTest
{
    private FakeClock $clock;

    private InMemoryIdempotencyStorage $storage;

    private HeaderIdempotencyKeyExtractor $extractor;

    private IdempotencyMiddleware $middleware;

    #[BeforeTest]
    public function setUp(): void
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

    public function implementsMiddlewareInterface(): void
    {
        Assert::instanceOf($this->middleware, MiddlewareInterface::class);
    }

    public function rejectsNonPositiveTtl(): void
    {
        try {
            new IdempotencyMiddleware(
                keyExtractor: $this->extractor,
                storage: $this->storage,
                responseFactory: new FakeResponseFactory(),
                clock: $this->clock,
                ttlSeconds: 0,
            );
            Assert::fail('Expected \InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            Assert::true(true);
        }
    }

    public function allowsTtlOfOne(): void
    {
        $middleware = new IdempotencyMiddleware(
            keyExtractor: $this->extractor,
            storage: $this->storage,
            responseFactory: new FakeResponseFactory(),
            clock: $this->clock,
            ttlSeconds: 1,
        );

        Assert::instanceOf($middleware, MiddlewareInterface::class);
    }

    public function passesThroughWhenNoKeyAndPolicyPassThrough(): void
    {
        $request = new FakeRequest();
        $handler = new FakeHandler();

        $response = $this->middleware->process($request, $handler);

        Assert::same($response->getStatusCode(), 200);
        Assert::same($handler->getCallCount(), 1);
    }

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

        Assert::same($response->getStatusCode(), 400);
        Assert::same($handler->getCallCount(), 0);
    }

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

        Assert::same($response->getStatusCode(), 200);
        Assert::same($handler->getCallCount(), 1);
    }

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

        Assert::same($response->getStatusCode(), 201);
        Assert::same($handler->getCallCount(), 1);
        Assert::string((string) $response->getBody())->contains('{"id":1}');
    }

    public function replayedResponseBodyIsReadableFromTheCurrentPosition(): void
    {
        $request = new FakeRequest(method: 'POST', path: '/api/users', body: '{}', headers: ['idempotency-key' => ['k']]);
        $this->middleware->process($request, new FakeHandler(responseStatus: 201, responseBody: '{"id":7}'));

        $replayed = $this->middleware->process($request, new FakeHandler(responseStatus: 201, responseBody: '{"id":7}'));

        Assert::same($replayed->getBody()->getContents(), '{"id":7}');
    }

    public function errorResponseBodyIsReadableFromTheCurrentPosition(): void
    {
        $first = new FakeRequest(method: 'POST', path: '/api/users', body: '{"a":1}', headers: ['idempotency-key' => ['k']]);
        $conflicting = new FakeRequest(method: 'POST', path: '/api/users', body: '{"a":2}', headers: ['idempotency-key' => ['k']]);
        $this->middleware->process($first, new FakeHandler());

        $response = $this->middleware->process($conflicting, new FakeHandler());

        Assert::same($response->getStatusCode(), 422);
        Assert::string($response->getBody()->getContents())->contains('Unprocessable');
    }

    public function firstResponseBodyIsRewoundAfterCapture(): void
    {
        $request = new FakeRequest(method: 'POST', path: '/api/users', body: '{}', headers: ['idempotency-key' => ['k']]);

        $response = $this->middleware->process($request, new FakeHandler(responseStatus: 200, responseBody: '{"ok":true}'));

        Assert::same($response->getBody()->getContents(), '{"ok":true}');
    }

    public function nonMutatingMethodPassesThroughEvenWithKey(): void
    {
        $request = new FakeRequest(method: 'GET', path: '/api/users', headers: ['idempotency-key' => ['k']]);
        $handler = new FakeHandler(responseStatus: 200, responseBody: 'fresh');

        $this->middleware->process($request, $handler);
        $this->middleware->process($request, $handler);

        Assert::same($handler->getCallCount(), 2);
    }

    public function honoursACustomMethodSet(): void
    {
        $middleware = new IdempotencyMiddleware(
            keyExtractor: $this->extractor,
            storage: $this->storage,
            responseFactory: new FakeResponseFactory(),
            clock: $this->clock,
            methods: ['delete'],
        );
        $request = new FakeRequest(method: 'DELETE', path: '/api/users/1', headers: ['idempotency-key' => ['k']]);
        $handler = new FakeHandler(responseStatus: 200, responseBody: 'gone');

        $middleware->process($request, $handler);
        $middleware->process($request, $handler);

        Assert::same($handler->getCallCount(), 1);
    }

    public function nonSuccessResponseIsNotCachedAndReleasesTheClaim(): void
    {
        $request = new FakeRequest(method: 'POST', path: '/api/users', body: '{}', headers: ['idempotency-key' => ['k']]);
        $handler = new FakeHandler(responseStatus: 409, responseBody: 'locked');

        $first = $this->middleware->process($request, $handler);
        $second = $this->middleware->process($request, $handler);

        Assert::same($first->getStatusCode(), 409);
        Assert::same($second->getStatusCode(), 409);
        Assert::same($handler->getCallCount(), 2);
    }

    public function cachesSuccessAtTheLowerBoundary(): void
    {
        $request = new FakeRequest(method: 'POST', path: '/p', body: '{}', headers: ['idempotency-key' => ['k']]);
        $handler = new FakeHandler(responseStatus: 200, responseBody: 'ok');

        $this->middleware->process($request, $handler);
        $this->middleware->process($request, $handler);

        Assert::same($handler->getCallCount(), 1);
    }

    public function doesNotCacheRedirectAtTheUpperBoundary(): void
    {
        $request = new FakeRequest(method: 'POST', path: '/p', body: '{}', headers: ['idempotency-key' => ['k']]);
        $handler = new FakeHandler(responseStatus: 300, responseBody: 'redirect');

        $this->middleware->process($request, $handler);
        $this->middleware->process($request, $handler);

        Assert::same($handler->getCallCount(), 2);
    }

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

        Assert::same($response->getStatusCode(), 422);
    }

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

        Assert::same($response->getStatusCode(), 200);
        Assert::same($handler->getCallCount(), 2);
    }

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

        Assert::same($response->getStatusCode(), 200);
        Assert::same($handler->getCallCount(), 2);
    }

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

        Assert::same($response->getHeaderLine('Content-Type'), 'application/json');
        Assert::string((string) $response->getBody())->contains('Unprocessable');
    }

    public function replayPreservesHeaders(): void
    {
        $request = new FakeRequest(
            method: 'POST',
            headers: ['idempotency-key' => ['key-1']],
        );
        $handler = new FakeHandler();

        $this->middleware->process($request, $handler);

        $response = $this->middleware->process($request, $handler);

        Assert::same($response->getStatusCode(), 200);
    }

    public function replayReturnsExactBody(): void
    {
        $request = new FakeRequest(
            method: 'POST',
            headers: ['idempotency-key' => ['key-1']],
        );
        $handler = new FakeHandler(responseBody: '{"id":42,"name":"test"}');

        $this->middleware->process($request, $handler);

        $response = $this->middleware->process($request, $handler);

        Assert::same((string) $response->getBody(), '{"id":42,"name":"test"}');
    }

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

        Assert::same($response->getStatusCode(), 201);
        Assert::same($response->getHeaderLine('X-Resource-Id'), '42');
        Assert::same($handler->getCallCount(), 1);
    }

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

        Assert::same($response->getStatusCode(), 201);
        Assert::same($handler->getCallCount(), 1);
    }

    public function storesRecordAfterFirstRequest(): void
    {
        $request = new FakeRequest(
            method: 'POST',
            body: '{"test":true}',
            headers: ['idempotency-key' => ['key-1']],
        );
        $handler = new FakeHandler(responseStatus: 201);

        $this->middleware->process($request, $handler);

        $record = $this->storage->load(new IdempotencyKey('key-1'));

        Assert::notNull($record);
        Assert::same($record->response->statusCode, 201);
    }

    public function claimedKeyReturnsConflictWhileInFlight(): void
    {
        $request = new FakeRequest(
            method: 'POST',
            body: '{"a":1}',
            headers: ['idempotency-key' => ['key-1']],
        );

        $this->storage->claim(
            new IdempotencyKey('key-1'),
            \Rasuvaeff\Yii3Idempotency\IdempotencyFingerprint::fromRequest($request),
        );

        $handler = new FakeHandler();
        $response = $this->middleware->process($request, $handler);

        Assert::same($response->getStatusCode(), 409);
        Assert::same($handler->getCallCount(), 0);
        Assert::string((string) $response->getBody())->contains('currently being processed');
    }

    public function serverErrorResponseIsNotStored(): void
    {
        $request = new FakeRequest(
            method: 'POST',
            headers: ['idempotency-key' => ['key-1']],
        );
        $handler = new FakeHandler(responseStatus: 500);

        $response = $this->middleware->process($request, $handler);

        Assert::same($response->getStatusCode(), 500);
        Assert::null($this->storage->load(new IdempotencyKey('key-1')));

        $retry = $this->middleware->process($request, new FakeHandler(responseStatus: 201));

        Assert::same($retry->getStatusCode(), 201);
        Assert::same($handler->getCallCount(), 1);
    }

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

        Assert::same($seenBody, '{"name":"John"}');
    }

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

        Assert::same($response->getStatusCode(), 422);
        Assert::same($handler->getCallCount(), 1);
    }

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
            Assert::fail('Expected RuntimeException');
        } catch (\RuntimeException $exception) {
            Assert::same($exception->getMessage(), 'boom');
        }

        $response = $this->middleware->process($request, new FakeHandler());

        Assert::same($response->getStatusCode(), 200);
    }
}
