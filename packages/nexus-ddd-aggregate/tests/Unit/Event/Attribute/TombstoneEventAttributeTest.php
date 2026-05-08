<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Event\Attribute;

use Monadial\Nexus\Ddd\Aggregate\Event\Attribute\TombstoneEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TombstoneEvent::class)]
final class TombstoneEventAttributeTest extends TestCase
{
    #[Test]
    public function constructsWithNameAndRemovedAt(): void
    {
        $attr = new TombstoneEvent(name: 'orders.OrderShippedManually', removedAt: 'v3.2.0');
        self::assertSame('orders.OrderShippedManually', $attr->name);
        self::assertSame('v3.2.0', $attr->removedAt);
    }
}
