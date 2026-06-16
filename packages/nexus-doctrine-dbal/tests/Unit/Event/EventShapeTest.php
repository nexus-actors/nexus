<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Tests\Unit\Event;

use Monadial\Nexus\Doctrine\Dbal\Event\ConnectionCreated;
use Monadial\Nexus\Doctrine\Dbal\Event\ConnectionDestroyed;
use Monadial\Nexus\Doctrine\Dbal\Event\ConnectionPoisoned;
use Monadial\Nexus\Doctrine\Dbal\Event\ConnectionReleased;
use Monadial\Nexus\Doctrine\Dbal\Event\ConnectionTaken;
use Monadial\Nexus\Doctrine\Dbal\Event\PoolExhausted;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolStats;
use Monadial\Nexus\Runtime\Duration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConnectionCreated::class)]
#[CoversClass(ConnectionDestroyed::class)]
#[CoversClass(ConnectionPoisoned::class)]
#[CoversClass(ConnectionTaken::class)]
#[CoversClass(ConnectionReleased::class)]
#[CoversClass(PoolExhausted::class)]
final class EventShapeTest extends TestCase
{
    #[Test]
    public function connectionTakenCarriesWaitTime(): void
    {
        $e = new ConnectionTaken('orders', Duration::millis(7));
        self::assertSame('orders', $e->poolName);
        self::assertTrue($e->waitTime->equals(Duration::millis(7)));
    }

    #[Test]
    public function connectionReleasedCarriesHeldFor(): void
    {
        $e = new ConnectionReleased('orders', Duration::millis(42));
        self::assertTrue($e->heldFor->equals(Duration::millis(42)));
    }

    #[Test]
    public function poolExhaustedCarriesStats(): void
    {
        $stats = PoolStats::empty();
        $e = new PoolExhausted('orders', $stats);
        self::assertSame($stats, $e->stats);
    }

    #[Test]
    public function lifecycleEventsCarryPoolName(): void
    {
        self::assertSame('o', (new ConnectionCreated('o'))->poolName);
        self::assertSame('o', (new ConnectionDestroyed('o'))->poolName);
        self::assertSame('o', (new ConnectionPoisoned('o', 'reason'))->poolName);
        self::assertSame('reason', (new ConnectionPoisoned('o', 'reason'))->reason);
    }
}
