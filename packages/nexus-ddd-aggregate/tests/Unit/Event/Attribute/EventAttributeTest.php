<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Event\Attribute;

use Monadial\Nexus\Ddd\Aggregate\Event\Attribute\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Event::class)]
final class EventAttributeTest extends TestCase
{
    #[Test]
    public function constructsWithNameAndExplicitVersion(): void
    {
        $attr = new Event(name: 'orders.OrderPlaced', version: 2);
        self::assertSame('orders.OrderPlaced', $attr->name);
        self::assertSame(2, $attr->version);
    }

    #[Test]
    public function defaultsVersionToOne(): void
    {
        $attr = new Event(name: 'orders.OrderPlaced');
        self::assertSame(1, $attr->version);
    }
}
