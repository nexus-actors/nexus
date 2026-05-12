<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Idempotency;

use InvalidArgumentException;
use Monadial\Nexus\Ddd\Bus\Idempotency\IdempotencyKey;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_repeat;
use function strlen;

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
    public function valueAtMaximumLengthIsAccepted(): void
    {
        $key = new IdempotencyKey(str_repeat('a', IdempotencyKey::MAX_LENGTH));

        self::assertSame(IdempotencyKey::MAX_LENGTH, strlen($key->value));
    }

    #[Test]
    public function valueOneByteOverMaximumThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds maximum');

        new IdempotencyKey(str_repeat('a', IdempotencyKey::MAX_LENGTH + 1));
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
