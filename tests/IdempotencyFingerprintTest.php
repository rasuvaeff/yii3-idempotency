<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency\Tests;

use Rasuvaeff\Yii3Idempotency\IdempotencyFingerprint;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(IdempotencyFingerprint::class)]
final class IdempotencyFingerprintTest
{
    public function createsFromRequest(): void
    {
        $request = new FakeRequest(
            method: 'POST',
            path: '/api/users',
            body: '{"name":"John"}',
        );

        $fp = IdempotencyFingerprint::fromRequest($request);

        Assert::same(strlen($fp->hash), 64);
    }

    public function sameRequestProducesSameFingerprint(): void
    {
        $a = IdempotencyFingerprint::fromRequest(new FakeRequest(
            method: 'POST',
            path: '/api/users',
            body: '{"name":"John"}',
        ));
        $b = IdempotencyFingerprint::fromRequest(new FakeRequest(
            method: 'POST',
            path: '/api/users',
            body: '{"name":"John"}',
        ));

        Assert::true($a->equals($b));
    }

    public function differentMethodProducesDifferentFingerprint(): void
    {
        $a = IdempotencyFingerprint::fromRequest(new FakeRequest(method: 'POST', body: '{}'));
        $b = IdempotencyFingerprint::fromRequest(new FakeRequest(method: 'PUT', body: '{}'));

        Assert::false($a->equals($b));
    }

    public function differentPathProducesDifferentFingerprint(): void
    {
        $a = IdempotencyFingerprint::fromRequest(new FakeRequest(path: '/a'));
        $b = IdempotencyFingerprint::fromRequest(new FakeRequest(path: '/b'));

        Assert::false($a->equals($b));
    }

    public function differentBodyProducesDifferentFingerprint(): void
    {
        $a = IdempotencyFingerprint::fromRequest(new FakeRequest(body: '{"a":1}'));
        $b = IdempotencyFingerprint::fromRequest(new FakeRequest(body: '{"a":2}'));

        Assert::false($a->equals($b));
    }

    public function producesExpectedHash(): void
    {
        $request = new FakeRequest(
            method: 'POST',
            path: '/api/test',
            body: '{"data":1}',
        );

        $expected = hash('sha256', "POST\n/api/test\n\n{\"data\":1}");
        $fp = IdempotencyFingerprint::fromRequest($request);

        Assert::same($fp->hash, $expected);
    }

    public function producesExpectedHashFromAllFourParts(): void
    {
        $request = new FakeRequest(method: 'PUT', path: '/orders/7', query: 'a=1&b=2', body: '{"x":1}');

        $expected = hash('sha256', "PUT\n/orders/7\na=1&b=2\n{\"x\":1}");

        Assert::same(IdempotencyFingerprint::fromRequest($request)->hash, $expected);
    }

    public function differentQueryProducesDifferentFingerprint(): void
    {
        $a = IdempotencyFingerprint::fromRequest(new FakeRequest(path: '/orders', query: 'retry=1'));
        $b = IdempotencyFingerprint::fromRequest(new FakeRequest(path: '/orders', query: 'retry=2'));

        Assert::false($a->equals($b));
    }

    public function rewindsSeekableBodyAfterReading(): void
    {
        $request = new FakeRequest(body: '{"name":"John"}');

        IdempotencyFingerprint::fromRequest($request);

        Assert::same($request->getBody()->getContents(), '{"name":"John"}');
    }

    public function emptyBodyProducesDistinctHash(): void
    {
        $a = IdempotencyFingerprint::fromRequest(new FakeRequest(body: ''));
        $b = IdempotencyFingerprint::fromRequest(new FakeRequest(body: '{}'));

        Assert::false($a->equals($b));
    }
}
