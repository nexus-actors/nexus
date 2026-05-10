<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Idempotency;

use InvalidArgumentException;
use Monadial\Nexus\Ddd\Bus\Idempotency\IdempotencyKey;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IdempotencyKey::class)]
final class IdempotencyKeyTest extends TestCase
{
    #[Test]
    public function constructorAssignsValue(): void
    {
        $key = new IdempotencyKey('abc-123');

        self::assertSame('abc-123', $key->value);
    }

    #[Test]
    public function emptyValueThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new IdempotencyKey('');
    }

    #[Test]
    public function equalsReturnsTrueForIdenticalValues(): void
    {
        self::assertTrue((new IdempotencyKey('k'))->equals(new IdempotencyKey('k')));
    }

    #[Test]
    public function equalsReturnsFalseForDifferingValues(): void
    {
        self::assertFalse((new IdempotencyKey('a'))->equals(new IdempotencyKey('b')));
    }
}
