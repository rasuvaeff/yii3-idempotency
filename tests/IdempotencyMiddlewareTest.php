<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency\Tests;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Idempotency\CompositeScopeResolver;
use Rasuvaeff\Yii3Idempotency\DefaultFailureClassifier;
use Rasuvaeff\Yii3Idempotency\FailureClassifier;
use Rasuvaeff\Yii3Idempotency\FailureKind;
use Rasuvaeff\Yii3Idempotency\HeaderIdempotencyKeyExtractor;
use Rasuvaeff\Yii3Idempotency\IdempotencyFingerprint;
use Rasuvaeff\Yii3Idempotency\IdempotencyKey;
use Rasuvaeff\Yii3Idempotency\IdempotencyMiddleware;
use Rasuvaeff\Yii3Idempotency\IdempotencyPolicy;
use Rasuvaeff\Yii3Idempotency\InMemoryIdempotencyStorage;
use Rasuvaeff\Yii3Idempotency\MissingKeyException;
use Rasuvaeff\Yii3Idempotency\PayloadIdempotencyKeyExtractor;
use Rasuvaeff\Yii3Idempotency\RequestAttributeScopeResolver;
use Rasuvaeff\Yii3Idempotency\RequestTargetScopeResolver;
use Rasuvaeff\Yii3Idempotency\SharedKeyspaceScopeResolver;
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
            scopeResolver: new SharedKeyspaceScopeResolver(),
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
                scopeResolver: new SharedKeyspaceScopeResolver(),
                ttlSeconds: 0,
            );
            Assert::fail('Expected \InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            Assert::true(actual: true);
        }
    }

    public function allowsTtlOfOne(): void
    {
        $middleware = new IdempotencyMiddleware(
            keyExtractor: $this->extractor,
            storage: $this->storage,
            responseFactory: new FakeResponseFactory(),
            clock: $this->clock,
            scopeResolver: new SharedKeyspaceScopeResolver(),
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
            scopeResolver: new SharedKeyspaceScopeResolver(),
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

    public function nonSeekableRequestBodyIsRestoredForTheHandler(): void
    {
        $request = (new FakeRequest(method: 'POST', path: '/api/users', headers: ['idempotency-key' => ['k']]))
            ->withBody(new FakeBodyStream('{"a":1}', seekable: false));
        $capturing = new class implements RequestHandlerInterface {
            public ServerRequestInterface $received;

            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->received = $request;

                return new FakeResponse(200);
            }
        };

        $this->middleware->process($request, $capturing);

        Assert::same((string) $capturing->received->getBody(), '{"a":1}');
    }

    public function replayMatchesFingerprintOfANonSeekableRequestBody(): void
    {
        $first = (new FakeRequest(method: 'POST', path: '/api/users', headers: ['idempotency-key' => ['k']]))
            ->withBody(new FakeBodyStream('{"a":1}', seekable: false));
        $second = (new FakeRequest(method: 'POST', path: '/api/users', headers: ['idempotency-key' => ['k']]))
            ->withBody(new FakeBodyStream('{"a":1}', seekable: false));

        $this->middleware->process($first, new FakeHandler(responseStatus: 201, responseBody: '{"id":7}'));
        $replayed = $this->middleware->process($second, new FakeHandler(responseStatus: 201));

        Assert::same($replayed->getStatusCode(), 201);
        Assert::same($replayed->getBody()->getContents(), '{"id":7}');
    }

    public function nonSeekableResponseStillReachesTheFirstClient(): void
    {
        $request = new FakeRequest(method: 'POST', path: '/api/users', body: '{}', headers: ['idempotency-key' => ['k']]);
        $handler = new class implements RequestHandlerInterface {
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new FakeResponse(201))->withBody(new FakeBodyStream('{"id":9}', seekable: false));
            }
        };

        $response = $this->middleware->process($request, $handler);

        Assert::same($response->getStatusCode(), 201);
        Assert::string((string) $response->getBody())->contains('{"id":9}');
    }

    public function replayIsCompleteAfterANonSeekableOriginalResponse(): void
    {
        $first = new FakeRequest(method: 'POST', path: '/api/users', body: '{}', headers: ['idempotency-key' => ['k']]);
        $second = new FakeRequest(method: 'POST', path: '/api/users', body: '{}', headers: ['idempotency-key' => ['k']]);
        $handler = new class implements RequestHandlerInterface {
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new FakeResponse(201))->withBody(new FakeBodyStream('{"id":9}', seekable: false));
            }
        };
        $this->middleware->process($first, $handler);

        $replayed = $this->middleware->process($second, new FakeHandler(responseStatus: 201));

        Assert::same($replayed->getStatusCode(), 201);
        Assert::same($replayed->getBody()->getContents(), '{"id":9}');
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
            scopeResolver: new SharedKeyspaceScopeResolver(),
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
            scopeResolver: new SharedKeyspaceScopeResolver(),
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
            scopeResolver: new SharedKeyspaceScopeResolver(),
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

        $record = $this->storage->load($this->storageKey('key-1'));

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
            $this->storageKey('key-1'),
            IdempotencyFingerprint::fromRequest($request),
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
        Assert::null($this->storage->load($this->storageKey('key-1')));

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

    public function replayRestoresEveryCapturedHeader(): void
    {
        $request = new FakeRequest(
            method: 'POST',
            headers: ['idempotency-key' => ['key-1']],
        );
        $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
            #[\Override]
            public function handle(
                \Psr\Http\Message\ServerRequestInterface $request,
            ): \Psr\Http\Message\ResponseInterface {
                $response = new FakeResponse(201);
                $response = $response->withHeader(name: 'Content-Type', value: 'application/json');
                $response = $response->withHeader(name: 'Location', value: '/orders/1');

                return $response->withHeader(name: 'X-Trace', value: 'abc');
            }
        };

        $this->middleware->process($request, $handler);
        $replay = $this->middleware->process($request, new FakeHandler());

        Assert::same($replay->getHeader('Content-Type'), ['application/json']);
        Assert::same($replay->getHeader('Location'), ['/orders/1']);
        Assert::same($replay->getHeader('X-Trace'), ['abc']);
    }

    public function lowerCaseRequestMethodIsStillIdempotent(): void
    {
        $request = new FakeRequest(
            method: 'post',
            headers: ['idempotency-key' => ['key-1']],
        );
        $handler = new FakeHandler();

        $first = $this->middleware->process($request, $handler);
        $replay = $this->middleware->process($request, $handler);

        Assert::same($first->getStatusCode(), 200);
        Assert::same($replay->getStatusCode(), 200);
        Assert::same($handler->getCallCount(), 1);
    }

    public function domainFailureIsRenderedCachedAndReplayed(): void
    {
        $renderer = new FakeDomainFailureRenderer();
        $middleware = $this->middlewareWith(renderer: $renderer);
        $request = $this->keyedRequest();
        $handler = new FakeHandler(throwable: new FakeDomainException('payment declined'));

        $first = $middleware->process($request, $handler);

        Assert::same($first->getStatusCode(), 422);
        Assert::string((string) $first->getBody())->contains('payment declined');

        $replay = $middleware->process($request, $handler);

        Assert::same($replay->getStatusCode(), 422);
        Assert::same((string) $replay->getBody(), (string) $first->getBody());
        Assert::same($replay->getHeader('Content-Type'), ['application/json']);
        Assert::same($handler->getCallCount(), 1);
        Assert::same($renderer->getCallCount(), 1);
    }

    public function retryableFailureIsNotCached(): void
    {
        $renderer = new FakeDomainFailureRenderer();
        $middleware = $this->middlewareWith(renderer: $renderer);
        $request = $this->keyedRequest();
        $handler = new FakeHandler(throwable: new FakeRetryableException('connection reset'));

        try {
            $middleware->process($request, $handler);
            Assert::fail('Expected FakeRetryableException');
        } catch (FakeRetryableException $exception) {
            Assert::same($exception->getMessage(), 'connection reset');
        }

        Assert::same($renderer->getCallCount(), 0);
        Assert::null($this->storage->load($this->storageKey('key-1')));

        $retry = $middleware->process($request, new FakeHandler());

        Assert::same($retry->getStatusCode(), 200);
    }

    public function failureClassifiedAsBugByAnOverrideIsNotCached(): void
    {
        $middleware = $this->middlewareWith(
            renderer: new FakeDomainFailureRenderer(),
            classifier: new DefaultFailureClassifier([FakeDomainException::class => FailureKind::Bug]),
        );
        $request = $this->keyedRequest();

        try {
            $middleware->process($request, new FakeHandler(throwable: new FakeDomainException('bug')));
            Assert::fail('Expected FakeDomainException');
        } catch (FakeDomainException) {
            Assert::true(actual: true);
        }

        Assert::null($this->storage->load($this->storageKey('key-1')));
    }

    public function declinedDomainFailureStaysRetryable(): void
    {
        $renderer = new FakeDomainFailureRenderer(declines: true);
        $middleware = $this->middlewareWith(renderer: $renderer);
        $request = $this->keyedRequest();

        try {
            $middleware->process($request, new FakeHandler(throwable: new FakeDomainException('declined')));
            Assert::fail('Expected FakeDomainException');
        } catch (FakeDomainException) {
            Assert::true(actual: true);
        }

        Assert::same($renderer->getCallCount(), 1);
        Assert::null($this->storage->load($this->storageKey('key-1')));

        $retry = $middleware->process($request, new FakeHandler());

        Assert::same($retry->getStatusCode(), 200);
    }

    public function domainFailureStaysRetryableWithoutARenderer(): void
    {
        $request = $this->keyedRequest();

        try {
            $this->middleware->process($request, new FakeHandler(throwable: new FakeDomainException('declined')));
            Assert::fail('Expected FakeDomainException');
        } catch (FakeDomainException) {
            Assert::true(actual: true);
        }

        Assert::null($this->storage->load($this->storageKey('key-1')));
    }

    public function classifierIsNotConsultedWithoutARenderer(): void
    {
        $classifier = new CountingFailureClassifier();
        $middleware = new IdempotencyMiddleware(
            keyExtractor: $this->extractor,
            storage: $this->storage,
            responseFactory: new FakeResponseFactory(),
            clock: $this->clock,
            scopeResolver: new SharedKeyspaceScopeResolver(),
            failureClassifier: $classifier,
        );

        try {
            $middleware->process($this->keyedRequest(), new FakeHandler(throwable: new FakeDomainException('x')));
            Assert::fail('Expected FakeDomainException');
        } catch (FakeDomainException) {
            Assert::true(actual: true);
        }

        Assert::same($classifier->getCallCount(), 0);
    }

    public function brokenRendererReleasesTheClaimAndRethrowsTheOriginalFailure(): void
    {
        $middleware = $this->middlewareWith(renderer: new FakeDomainFailureRenderer(throws: true));
        $request = $this->keyedRequest();

        try {
            $middleware->process($request, new FakeHandler(throwable: new FakeDomainException('declined')));
            Assert::fail('Expected FakeDomainException');
        } catch (FakeDomainException $exception) {
            Assert::same($exception->getMessage(), 'declined');
        }

        // the claim must not outlive the failed caching attempt, or every later
        // request under this key answers 409 until the claim TTL expires
        $retry = $middleware->process($request, new FakeHandler());

        Assert::same($retry->getStatusCode(), 200);
    }

    public function storageFailureWhileCachingADomainFailureReleasesTheClaim(): void
    {
        $storage = new FailingIdempotencyStorage(new InMemoryIdempotencyStorage($this->clock));
        $middleware = new IdempotencyMiddleware(
            keyExtractor: $this->extractor,
            storage: $storage,
            responseFactory: new FakeResponseFactory(),
            clock: $this->clock,
            scopeResolver: new SharedKeyspaceScopeResolver(),
            domainFailureRenderer: new FakeDomainFailureRenderer(),
        );
        $request = $this->keyedRequest();

        try {
            $middleware->process($request, new FakeHandler(throwable: new FakeDomainException('declined')));
            Assert::fail('Expected FakeDomainException');
        } catch (FakeDomainException $exception) {
            Assert::same($exception->getMessage(), 'declined');
        }

        Assert::true($storage->claim($this->storageKey('key-1'), new IdempotencyFingerprint('any')));
    }

    public function storageFailureOnASuccessfulResponseReleasesTheClaim(): void
    {
        $storage = new FailingIdempotencyStorage(new InMemoryIdempotencyStorage($this->clock));
        $middleware = new IdempotencyMiddleware(
            keyExtractor: $this->extractor,
            storage: $storage,
            responseFactory: new FakeResponseFactory(),
            clock: $this->clock,
            scopeResolver: new SharedKeyspaceScopeResolver(),
        );

        try {
            $middleware->process($this->keyedRequest(), new FakeHandler());
            Assert::fail('Expected RuntimeException');
        } catch (\RuntimeException $exception) {
            Assert::same($exception->getMessage(), 'storage is down');
        }

        Assert::true($storage->claim($this->storageKey('key-1'), new IdempotencyFingerprint('any')));
    }

    public function failingReleaseDoesNotReplaceTheHandlerFailure(): void
    {
        $middleware = new IdempotencyMiddleware(
            keyExtractor: $this->extractor,
            storage: new FailingIdempotencyStorage(
                inner: new InMemoryIdempotencyStorage($this->clock),
                failOnStore: false,
                failOnRelease: true,
            ),
            responseFactory: new FakeResponseFactory(),
            clock: $this->clock,
            scopeResolver: new SharedKeyspaceScopeResolver(),
        );

        try {
            $middleware->process(
                $this->keyedRequest(),
                new FakeHandler(throwable: new FakeDomainException('payment declined')),
            );
            Assert::fail('Expected FakeDomainException');
        } catch (FakeDomainException $exception) {
            // the cleanup error must not surface in place of the reason the
            // request failed — the caller can only act on the latter
            Assert::same($exception->getMessage(), 'payment declined');
        }
    }

    public function failingReleaseDoesNotReplaceTheStorageFailure(): void
    {
        $middleware = new IdempotencyMiddleware(
            keyExtractor: $this->extractor,
            storage: new FailingIdempotencyStorage(
                inner: new InMemoryIdempotencyStorage($this->clock),
                failOnRelease: true,
            ),
            responseFactory: new FakeResponseFactory(),
            clock: $this->clock,
            scopeResolver: new SharedKeyspaceScopeResolver(),
        );

        try {
            $middleware->process($this->keyedRequest(), new FakeHandler());
            Assert::fail('Expected RuntimeException');
        } catch (\RuntimeException $exception) {
            Assert::same($exception->getMessage(), 'storage is down');
        }
    }

    public function cachedDomainFailureStillDetectsAPayloadMismatch(): void
    {
        $middleware = $this->middlewareWith(renderer: new FakeDomainFailureRenderer());
        $handler = new FakeHandler(throwable: new FakeDomainException('declined'));

        $middleware->process($this->keyedRequest(body: '{"amount":1}'), $handler);
        $mismatch = $middleware->process($this->keyedRequest(body: '{"amount":2}'), $handler);

        Assert::same($mismatch->getStatusCode(), 422);
        Assert::string((string) $mismatch->getBody())->contains('different payload');
        Assert::same($handler->getCallCount(), 1);
    }

    /**
     * The roadmap guarantee for item 1: once a domain failure is cached, a retry
     * under the same key never re-runs the handler, whatever status the renderer
     * chose.
     */
    #[Property(runs: 200)]
    public function cachedDomainFailureIsNeverReExecuted(int $statusCode, string $message): void
    {
        $clock = new FakeClock();
        $storage = new InMemoryIdempotencyStorage($clock);
        $middleware = new IdempotencyMiddleware(
            keyExtractor: new HeaderIdempotencyKeyExtractor(),
            storage: $storage,
            responseFactory: new FakeResponseFactory(),
            clock: $clock,
            scopeResolver: new SharedKeyspaceScopeResolver(),
            domainFailureRenderer: new FakeDomainFailureRenderer(statusCode: $statusCode),
        );
        $request = $this->keyedRequest();
        $handler = new FakeHandler(throwable: new FakeDomainException($message));

        $first = $middleware->process($request, $handler);
        $replay = $middleware->process($request, $handler);

        Assert::same($handler->getCallCount(), 1);
        Assert::same($replay->getStatusCode(), $first->getStatusCode());
        Assert::same((string) $replay->getBody(), (string) $first->getBody());
    }

    /** @return array<string, ArbitraryInterface> */
    public static function cachedDomainFailureIsNeverReExecutedGenerators(): array
    {
        return [
            'statusCode' => Gen::intBetween(400, 499),
            'message' => Gen::stringAscii(),
        ];
    }

    public function malformedKeyYieldsBadRequestInsteadOfServerError(): void
    {
        $request = new FakeRequest(
            method: 'POST',
            path: '/api/payments',
            headers: ['idempotency-key' => ['not a valid key']],
        );
        $handler = new FakeHandler();

        $response = $this->middleware->process($request, $handler);

        Assert::same($response->getStatusCode(), 400);
        Assert::same($handler->getCallCount(), 0);
        Assert::string((string) $response->getBody())->contains('Idempotency key has an invalid format');
    }

    public function overLongKeyYieldsBadRequest(): void
    {
        $request = new FakeRequest(
            method: 'POST',
            path: '/api/payments',
            headers: ['idempotency-key' => [str_repeat('a', 256)]],
        );

        Assert::same($this->middleware->process($request, new FakeHandler())->getStatusCode(), 400);
    }

    /**
     * A required-but-absent key is a contract of the extractor, not a malformed
     * value: it must keep propagating past the 400 guard.
     */
    public function missingKeyExceptionStillPropagates(): void
    {
        $middleware = new IdempotencyMiddleware(
            keyExtractor: new PayloadIdempotencyKeyExtractor('order.id'),
            storage: $this->storage,
            responseFactory: new FakeResponseFactory(),
            clock: $this->clock,
            scopeResolver: new SharedKeyspaceScopeResolver(),
        );

        try {
            $middleware->process(new FakeRequest(method: 'POST'), new FakeHandler());
            Assert::fail('Expected MissingKeyException');
        } catch (MissingKeyException) {
            Assert::true(actual: true);
        }
    }

    /**
     * A scope resolver blowing up is a deployment error (the application decides
     * what the attribute holds), so it must not be answered as a bad request.
     */
    public function scopeResolverFailureIsNotReportedAsBadRequest(): void
    {
        $middleware = new IdempotencyMiddleware(
            keyExtractor: $this->extractor,
            storage: $this->storage,
            responseFactory: new FakeResponseFactory(),
            clock: $this->clock,
            scopeResolver: new RequestAttributeScopeResolver(),
        );

        try {
            $middleware->process(
                new FakeRequest(
                    method: 'POST',
                    headers: ['idempotency-key' => ['key-1']],
                    attributes: ['user' => new \stdClass()],
                ),
                new FakeHandler(),
            );
            Assert::fail('Expected \InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            Assert::true(actual: true);
        }
    }

    public function setCookieIsNeitherStoredNorReplayed(): void
    {
        $handler = new FakeHandler(responseHeader: 'Set-Cookie', responseHeaderValue: 'PHPSESSID=secret');

        $first = $this->middleware->process($this->keyedRequest(), $handler);
        Assert::same($first->getHeader('Set-Cookie'), ['PHPSESSID=secret']);

        $replay = $this->middleware->process($this->keyedRequest(), $handler);

        Assert::same($handler->getCallCount(), 1);
        Assert::same($replay->getHeader('Set-Cookie'), []);
    }

    /**
     * The deny-list is matched case-insensitively: a handler is free to spell
     * the header the way PSR-7 preserves it.
     */
    public function excludedHeadersAreMatchedCaseInsensitively(): void
    {
        $handler = new FakeHandler(responseHeaders: ['SET-COOKIE' => 'PHPSESSID=secret']);

        $this->middleware->process($this->keyedRequest(), $handler);
        $replay = $this->middleware->process($this->keyedRequest(), $handler);

        Assert::same($handler->getCallCount(), 1);
        Assert::same($replay->getHeader('Set-Cookie'), []);
    }

    /**
     * An excluded header must skip only itself: everything after it in the
     * header map still has to be captured.
     */
    public function headersAfterAnExcludedOneAreStillCaptured(): void
    {
        $handler = new FakeHandler(responseHeaders: [
            'Set-Cookie' => 'PHPSESSID=secret',
            'X-Trace-Id' => 'trace-1',
        ]);

        $this->middleware->process($this->keyedRequest(), $handler);
        $replay = $this->middleware->process($this->keyedRequest(), $handler);

        Assert::same($replay->getHeader('Set-Cookie'), []);
        Assert::same($replay->getHeader('X-Trace-Id'), ['trace-1']);
    }

    public function additionalExcludedResponseHeadersAreConfigurable(): void
    {
        $middleware = new IdempotencyMiddleware(
            keyExtractor: $this->extractor,
            storage: $this->storage,
            responseFactory: new FakeResponseFactory(),
            clock: $this->clock,
            scopeResolver: new SharedKeyspaceScopeResolver(),
            additionalExcludedResponseHeaders: ['X-Secret'],
        );
        $handler = new FakeHandler(responseHeader: 'X-Secret', responseHeaderValue: 'shh');

        $middleware->process($this->keyedRequest(), $handler);
        $replay = $middleware->process($this->keyedRequest(), $handler);

        Assert::same($replay->getHeader('X-Secret'), []);
    }

    /**
     * Regression: the argument used to *replace* the built-in list, so hiding
     * one header of your own silently re-enabled storing and replaying
     * `Set-Cookie` and every hop-by-hop header.
     */
    public function aCustomListNeverReEnablesTheBuiltInExclusions(): void
    {
        $middleware = new IdempotencyMiddleware(
            keyExtractor: $this->extractor,
            storage: $this->storage,
            responseFactory: new FakeResponseFactory(),
            clock: $this->clock,
            scopeResolver: new SharedKeyspaceScopeResolver(),
            additionalExcludedResponseHeaders: ['X-Secret'],
        );
        $handler = new FakeHandler(responseHeaders: [
            'X-Secret' => 'shh',
            'Set-Cookie' => 'PHPSESSID=secret',
            'Connection' => 'keep-alive',
            'X-Trace-Id' => 'trace-1',
        ]);

        $middleware->process($this->keyedRequest(), $handler);
        $replay = $middleware->process($this->keyedRequest(), $handler);

        Assert::same($handler->getCallCount(), 1);
        Assert::same($replay->getHeader('X-Secret'), []);
        Assert::same($replay->getHeader('Set-Cookie'), []);
        Assert::same($replay->getHeader('Connection'), []);
        Assert::same($replay->getHeader('X-Trace-Id'), ['trace-1']);
    }

    /**
     * An empty list is the default and must behave exactly like the built-in
     * one — the caller-supplied names only ever extend it.
     */
    public function anEmptyAdditionalListKeepsTheBuiltInExclusions(): void
    {
        $middleware = new IdempotencyMiddleware(
            keyExtractor: $this->extractor,
            storage: $this->storage,
            responseFactory: new FakeResponseFactory(),
            clock: $this->clock,
            scopeResolver: new SharedKeyspaceScopeResolver(),
            additionalExcludedResponseHeaders: [],
        );
        $handler = new FakeHandler(responseHeader: 'Set-Cookie', responseHeaderValue: 'PHPSESSID=secret');

        $middleware->process($this->keyedRequest(), $handler);
        $replay = $middleware->process($this->keyedRequest(), $handler);

        Assert::same($replay->getHeader('Set-Cookie'), []);
    }

    /**
     * Regression for the cross-caller replay: an identical key and an identical
     * payload from a different principal must run the handler again and get its
     * own response, never the first caller's.
     */
    public function differentCallersNeverShareARecord(): void
    {
        $middleware = new IdempotencyMiddleware(
            keyExtractor: $this->extractor,
            storage: $this->storage,
            responseFactory: new FakeResponseFactory(),
            clock: $this->clock,
            scopeResolver: new RequestAttributeScopeResolver(),
        );
        $handler = new FakeHandler();

        $middleware->process($this->callerRequest('alice'), $handler);
        $middleware->process($this->callerRequest('mallory'), $handler);

        Assert::same($handler->getCallCount(), 2);
    }

    public function theSameCallerStillReplays(): void
    {
        $middleware = new IdempotencyMiddleware(
            keyExtractor: $this->extractor,
            storage: $this->storage,
            responseFactory: new FakeResponseFactory(),
            clock: $this->clock,
            scopeResolver: new RequestAttributeScopeResolver(),
        );
        $handler = new FakeHandler();

        $middleware->process($this->callerRequest('alice'), $handler);
        $middleware->process($this->callerRequest('alice'), $handler);

        Assert::same($handler->getCallCount(), 1);
    }

    /**
     * The same key under two endpoints is two records: the endpoint scope rides
     * on top of the caller scope through the composite.
     */
    public function differentEndpointsNeverShareARecord(): void
    {
        $middleware = new IdempotencyMiddleware(
            keyExtractor: $this->extractor,
            storage: $this->storage,
            responseFactory: new FakeResponseFactory(),
            clock: $this->clock,
            scopeResolver: new CompositeScopeResolver(
                new RequestAttributeScopeResolver(),
                new RequestTargetScopeResolver(),
            ),
        );
        $handler = new FakeHandler();

        $middleware->process($this->callerRequest('alice', '/api/payments'), $handler);
        $middleware->process($this->callerRequest('alice', '/api/refunds'), $handler);

        Assert::same($handler->getCallCount(), 2);
    }

    /**
     * The key the middleware actually addresses: every key is namespaced by the
     * scope resolver before it reaches storage.
     */
    private function storageKey(string $value): IdempotencyKey
    {
        return (new SharedKeyspaceScopeResolver())
            ->resolve(new FakeRequest())
            ->apply(new IdempotencyKey($value));
    }

    private function callerRequest(string $caller, string $path = '/api/payments'): FakeRequest
    {
        return new FakeRequest(
            method: 'POST',
            path: $path,
            body: '{"amount":1}',
            headers: ['idempotency-key' => ['key-1']],
            attributes: ['user' => $caller],
        );
    }

    private function middlewareWith(
        FakeDomainFailureRenderer $renderer,
        ?FailureClassifier $classifier = null,
    ): IdempotencyMiddleware {
        return new IdempotencyMiddleware(
            keyExtractor: $this->extractor,
            storage: $this->storage,
            responseFactory: new FakeResponseFactory(),
            clock: $this->clock,
            scopeResolver: new SharedKeyspaceScopeResolver(),
            ttlSeconds: 3600,
            domainFailureRenderer: $renderer,
            failureClassifier: $classifier,
        );
    }

    private function keyedRequest(string $body = '{"amount":1}'): FakeRequest
    {
        return new FakeRequest(
            method: 'POST',
            path: '/api/payments',
            body: $body,
            headers: ['idempotency-key' => ['key-1']],
        );
    }
}
