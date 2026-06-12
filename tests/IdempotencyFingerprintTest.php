<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3Idempotency\IdempotencyFingerprint;

#[CoversClass(IdempotencyFingerprint::class)]
final class IdempotencyFingerprintTest extends TestCase
{
    #[Test]
    public function createsFromRequest(): void
    {
        $request = new FakeRequest(
            method: 'POST',
            path: '/api/users',
            body: '{"name":"John"}',
        );

        $fp = IdempotencyFingerprint::fromRequest($request);

        $this->assertSame(64, strlen($fp->hash));
    }

    #[Test]
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

        $this->assertTrue($a->equals($b));
    }

    #[Test]
    public function differentMethodProducesDifferentFingerprint(): void
    {
        $a = IdempotencyFingerprint::fromRequest(new FakeRequest(method: 'POST', body: '{}'));
        $b = IdempotencyFingerprint::fromRequest(new FakeRequest(method: 'PUT', body: '{}'));

        $this->assertFalse($a->equals($b));
    }

    #[Test]
    public function differentPathProducesDifferentFingerprint(): void
    {
        $a = IdempotencyFingerprint::fromRequest(new FakeRequest(path: '/a'));
        $b = IdempotencyFingerprint::fromRequest(new FakeRequest(path: '/b'));

        $this->assertFalse($a->equals($b));
    }

    #[Test]
    public function differentBodyProducesDifferentFingerprint(): void
    {
        $a = IdempotencyFingerprint::fromRequest(new FakeRequest(body: '{"a":1}'));
        $b = IdempotencyFingerprint::fromRequest(new FakeRequest(body: '{"a":2}'));

        $this->assertFalse($a->equals($b));
    }

    #[Test]
    public function producesExpectedHash(): void
    {
        $request = new FakeRequest(
            method: 'POST',
            path: '/api/test',
            body: '{"data":1}',
        );

        $expected = hash('sha256', "POST\n/api/test\n\n{\"data\":1}");
        $fp = IdempotencyFingerprint::fromRequest($request);

        $this->assertSame($expected, $fp->hash);
    }

    #[Test]
    public function producesExpectedHashFromAllFourParts(): void
    {
        // Method, path, query and body are all distinct, so any swapped/dropped
        // concat operand changes the digest.
        $request = new FakeRequest(method: 'PUT', path: '/orders/7', query: 'a=1&b=2', body: '{"x":1}');

        $expected = hash('sha256', "PUT\n/orders/7\na=1&b=2\n{\"x\":1}");

        $this->assertSame($expected, IdempotencyFingerprint::fromRequest($request)->hash);
    }

    #[Test]
    public function differentQueryProducesDifferentFingerprint(): void
    {
        $a = IdempotencyFingerprint::fromRequest(new FakeRequest(path: '/orders', query: 'retry=1'));
        $b = IdempotencyFingerprint::fromRequest(new FakeRequest(path: '/orders', query: 'retry=2'));

        $this->assertFalse($a->equals($b));
    }

    #[Test]
    public function rewindsSeekableBodyAfterReading(): void
    {
        $request = new FakeRequest(body: '{"name":"John"}');

        IdempotencyFingerprint::fromRequest($request);

        $this->assertSame('{"name":"John"}', $request->getBody()->getContents());
    }

    #[Test]
    public function emptyBodyProducesDistinctHash(): void
    {
        $a = IdempotencyFingerprint::fromRequest(new FakeRequest(body: ''));
        $b = IdempotencyFingerprint::fromRequest(new FakeRequest(body: '{}'));

        $this->assertFalse($a->equals($b));
    }
}
