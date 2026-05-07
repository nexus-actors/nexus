<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Value;

use Monadial\Nexus\Ddd\Core\Exception\InvalidIdentifierException;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use Monadial\Nexus\Ddd\Core\Tests\Support\TestUuidId;
use Monadial\Nexus\Ddd\Core\Value\UuidValue;
use Monadial\Nexus\Ddd\Core\Value\WrappedValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(UuidValue::class)]
final class UuidValueTest extends TestCase
{
    #[Test]
    public function uuidValueIsBothWrappedValueAndIdentifier(): void
    {
        $uuid = (string) Uuid::v7();
        $v = new TestUuidId($uuid);
        self::assertInstanceOf(WrappedValue::class, $v);
        self::assertInstanceOf(Identifier::class, $v);
        self::assertInstanceOf(UuidValue::class, $v);
        self::assertSame($uuid, $v->value());
    }

    #[Test]
    public function fromStringRehydrates(): void
    {
        $uuid = (string) Uuid::v7();
        self::assertSame($uuid, TestUuidId::fromString($uuid)->value());
    }

    #[Test]
    public function malformedValueThrows(): void
    {
        $this->expectException(InvalidIdentifierException::class);
        new TestUuidId('not-a-uuid');
    }
}
