<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Value;

use Monadial\Nexus\Ddd\Core\Exception\InvalidIdentifierException;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
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
        $v = new UlidValue($ulid);
        self::assertInstanceOf(WrappedValue::class, $v);
        self::assertInstanceOf(Identifier::class, $v);
        self::assertSame($ulid, $v->value());
    }

    #[Test]
    public function fromStringRehydratesUlidValue(): void
    {
        $ulid = (new Ulid())->toBase32();
        $rehydrated = UlidValue::fromString($ulid);
        self::assertSame($ulid, $rehydrated->value());
    }

    #[Test]
    public function malformedValueThrows(): void
    {
        $this->expectException(InvalidIdentifierException::class);
        new UlidValue('not-a-ulid');
    }

    #[Test]
    public function equalsByTypeAndValue(): void
    {
        $ulid = (new Ulid())->toBase32();
        $a = new UlidValue($ulid);
        $b = new UlidValue($ulid);
        self::assertTrue($a->equals($b));
    }
}
