<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3Idempotency\IdempotencyKey;

#[CoversClass(IdempotencyKey::class)]
final class IdempotencyKeyTest extends TestCase
{
    #[Test]
    public function acceptsValidKey(): void
    {
        $key = new IdempotencyKey('abc-123.456_XYZ');

        $this->assertSame('abc-123.456_XYZ', $key->value);
    }

    #[Test]
    public function acceptsSingleChar(): void
    {
        $key = new IdempotencyKey('a');

        $this->assertSame('a', $key->value);
    }

    #[Test]
    public function acceptsMaxLength(): void
    {
        $value = str_repeat('a', 255);
        $key = new IdempotencyKey($value);

        $this->assertSame($value, $key->value);
    }

    #[Test]
    public function rejectsEmptyString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('between 1 and 255');

        new IdempotencyKey('');
    }

    #[Test]
    public function rejectsTooLong(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('between 1 and 255');

        new IdempotencyKey(str_repeat('a', 256));
    }

    #[Test]
    public function rejectsInvalidCharacters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid characters');

        new IdempotencyKey('key with spaces');
    }

    /**
     * @return array<string, list{string}>
     */
    public static function invalidKeyProvider(): array
    {
        return [
            'contains @' => ['key@value'],
            'contains !' => ['key!value'],
            'contains /' => ['key/value'],
            'contains :' => ['key:value'],
        ];
    }

    #[Test]
    #[DataProvider('invalidKeyProvider')]
    public function rejectsInvalidChars(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new IdempotencyKey($value);
    }

    #[Test]
    public function equalsReturnsTrueForSameKey(): void
    {
        $a = new IdempotencyKey('key-1');
        $b = new IdempotencyKey('key-1');

        $this->assertTrue($a->equals($b));
    }

    #[Test]
    public function equalsReturnsFalseForDifferentKey(): void
    {
        $a = new IdempotencyKey('key-1');
        $b = new IdempotencyKey('key-2');

        $this->assertFalse($a->equals($b));
    }
}
