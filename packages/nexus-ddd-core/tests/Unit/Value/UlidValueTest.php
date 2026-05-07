<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Value;

use Monadial\Nexus\Ddd\Core\Exception\InvalidIdentifierException;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use Monadial\Nexus\Ddd\Core\Tests\Support\TestUlidId;
use Monadial\Nexus\Ddd\Core\Value\UlidValue;
use Monadial\Nexus\Ddd\Core\Value\WrappedValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

#[CoversClass(UlidValue::class)]
final class UlidValueTest extends TestCase
{
    #[Test]
    public function ulidValueIsBothWrappedValueAndIdentifier(): void
    {
        $ulid = (new Ulid())->toBase32();
        $v = new TestUlidId($ulid);
        self::assertInstanceOf(WrappedValue::class, $v);
        self::assertInstanceOf(Identifier::class, $v);
        self::assertInstanceOf(UlidValue::class, $v);
        self::assertSame($ulid, $v->value());
    }

    #[Test]
    public function fromStringRehydratesConcreteSubclass(): void
    {
        $ulid = (new Ulid())->toBase32();
        $rehydrated = TestUlidId::fromString($ulid);
        self::assertInstanceOf(TestUlidId::class, $rehydrated);
        self::assertSame($ulid, $rehydrated->value());
    }

    #[Test]
    public function malformedValueThrows(): void
    {
        $this->expectException(InvalidIdentifierException::class);
        new TestUlidId('not-a-ulid');
    }

    #[Test]
    public function equalsByTypeAndValue(): void
    {
        $ulid = (new Ulid())->toBase32();
        $a = new TestUlidId($ulid);
        $b = new TestUlidId($ulid);
        self::assertTrue($a->equals($b));
    }
}
