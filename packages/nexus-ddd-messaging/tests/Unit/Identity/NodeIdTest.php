<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Identity;

use Monadial\Nexus\Ddd\Core\Value\UlidValue;
use Monadial\Nexus\Ddd\Messaging\Identity\NodeId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NodeId::class)]
final class NodeIdTest extends TestCase
{
    #[Test]
    public function generateReturnsUlidValueOfNodeIdType(): void
    {
        $id = NodeId::generate();
        self::assertInstanceOf(NodeId::class, $id);
        self::assertInstanceOf(UlidValue::class, $id);
        self::assertSame(26, strlen($id->value()));
    }

    #[Test]
    public function consecutiveCallsReturnDistinctIds(): void
    {
        $a = NodeId::generate();
        $b = NodeId::generate();
        self::assertNotSame($a->value(), $b->value());
    }
}
