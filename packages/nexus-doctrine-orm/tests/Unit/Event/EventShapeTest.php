<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Unit\Event;

use Monadial\Nexus\Doctrine\Orm\Event\EntityManagerCleared;
use Monadial\Nexus\Doctrine\Orm\Event\EntityManagerCreated;
use Monadial\Nexus\Doctrine\Orm\Event\EntityManagerEvicted;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EntityManagerCreated::class)]
#[CoversClass(EntityManagerCleared::class)]
#[CoversClass(EntityManagerEvicted::class)]
final class EventShapeTest extends TestCase
{
    #[Test]
    public function createdAndClearedCarryPoolName(): void
    {
        self::assertSame('orders', (new EntityManagerCreated('orders'))->poolName);
        self::assertSame('orders', (new EntityManagerCleared('orders'))->poolName);
    }

    #[Test]
    public function evictedCarriesReason(): void
    {
        $e = new EntityManagerEvicted('orders', 'em-closed');
        self::assertSame('orders', $e->poolName);
        self::assertSame('em-closed', $e->reason);
    }
}
