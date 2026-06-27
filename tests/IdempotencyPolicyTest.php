<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency\Tests;

use Rasuvaeff\Yii3Idempotency\IdempotencyPolicy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(IdempotencyPolicy::class)]
final class IdempotencyPolicyTest
{
    #[DataProvider('validConfigProvider')]
    public function fromConfigValueResolvesExpectedCase(
        string $value,
        IdempotencyPolicy $expected,
    ): void {
        Assert::same(IdempotencyPolicy::fromConfigValue($value), $expected);
    }

    public static function validConfigProvider(): iterable
    {
        yield 'pass_through' => ['pass_through', IdempotencyPolicy::PassThrough];
        yield 'passthrough' => ['passthrough', IdempotencyPolicy::PassThrough];
        yield 'reject' => ['reject', IdempotencyPolicy::Reject];
        yield 'uppercase' => ['REJECT', IdempotencyPolicy::Reject];
    }

    public function fromConfigValueThrowsOnInvalidValue(): void
    {
        try {
            IdempotencyPolicy::fromConfigValue('unknown');
            Assert::fail('Expected \InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Invalid idempotency policy "unknown"');
        }
    }
}
