<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Net;

use InvalidArgumentException;
use Monadial\Nexus\Core\Net\Port;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Port::class)]
final class PortTest extends TestCase
{
    #[Test]
    public function ofAcceptsZero(): void
    {
        $port = Port::of(0);

        self::assertSame(0, $port->value);
    }

    #[Test]
    public function ofAcceptsOne(): void
    {
        $port = Port::of(1);

        self::assertSame(1, $port->value);
    }

    #[Test]
    public function ofAcceptsMaxPort(): void
    {
        $port = Port::of(65535);

        self::assertSame(65535, $port->value);
    }

    #[Test]
    public function ofRejectsNegativeOne(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('range');

        Port::of(-1);
    }

    #[Test]
    public function ofRejectsAboveMax(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('range');

        Port::of(65536);
    }

    #[Test]
    public function equalsReturnsTrueForSameValue(): void
    {
        self::assertTrue(Port::of(7355)->equals(Port::of(7355)));
    }

    #[Test]
    public function equalsReturnsFalseForDifferentValue(): void
    {
        self::assertFalse(Port::of(7355)->equals(Port::of(7356)));
    }

    #[Test]
    public function toStringReturnsValueAsString(): void
    {
        self::assertSame('7355', (string) Port::of(7355));
    }
}
