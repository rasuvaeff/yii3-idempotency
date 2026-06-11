<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3Idempotency\IdempotencyPolicy;

#[CoversClass(IdempotencyPolicy::class)]
final class IdempotencyPolicyTest extends TestCase
{
    #[Test]
    #[DataProvider('validConfigProvider')]
    public function fromConfigValueResolvesExpectedCase(
        string $value,
        IdempotencyPolicy $expected,
    ): void {
        $this->assertSame($expected, IdempotencyPolicy::fromConfigValue($value));
    }

    public static function validConfigProvider(): iterable
    {
        yield 'pass_through' => ['pass_through', IdempotencyPolicy::PassThrough];
        yield 'passthrough' => ['passthrough', IdempotencyPolicy::PassThrough];
        yield 'reject' => ['reject', IdempotencyPolicy::Reject];
        yield 'uppercase' => ['REJECT', IdempotencyPolicy::Reject];
    }

    #[Test]
    public function fromConfigValueThrowsOnInvalidValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid idempotency policy "unknown"');

        IdempotencyPolicy::fromConfigValue('unknown');
    }
}
