<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Identity;

use Monadial\Nexus\Ddd\Core\Identity\IdGenerator;
use Monadial\Nexus\Ddd\Core\Identity\UlidGenerator;
use Monadial\Nexus\Ddd\Core\Value\UlidValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UlidGenerator::class)]
final class UlidGeneratorTest extends TestCase
{
    #[Test]
    public function nextReturnsAUlidIdentifier(): void
    {
        $gen = new UlidGenerator();
        self::assertInstanceOf(IdGenerator::class, $gen);

        $id = $gen->next();
        self::assertInstanceOf(UlidValue::class, $id);
        self::assertSame(26, strlen($id->value()));   // ULID canonical length
    }

    #[Test]
    public function consecutiveCallsReturnDifferentIds(): void
    {
        $gen = new UlidGenerator();
        $a = $gen->next();
        $b = $gen->next();
        self::assertNotSame($a->value(), $b->value());
    }
}
