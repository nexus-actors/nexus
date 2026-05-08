<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Identity;

use Monadial\Nexus\Ddd\Core\Value\UlidValue;
use Monadial\Nexus\Ddd\Messaging\Identity\MessageId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MessageId::class)]
final class MessageIdTest extends TestCase
{
    #[Test]
    public function generateReturnsUlidValueOfMessageIdType(): void
    {
        $id = MessageId::generate();
        self::assertInstanceOf(MessageId::class, $id);
        self::assertInstanceOf(UlidValue::class, $id);
        self::assertSame(26, strlen($id->value()));
    }

    #[Test]
    public function consecutiveCallsReturnDistinctIds(): void
    {
        $a = MessageId::generate();
        $b = MessageId::generate();
        self::assertNotSame($a->value(), $b->value());
    }

    #[Test]
    public function fromStringRoundTrips(): void
    {
        $a = MessageId::generate();
        $b = MessageId::fromString($a->value());
        self::assertTrue($a->equals($b));
    }
}
