<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Tests\Unit\Pool;

use Monadial\Nexus\Doctrine\Dbal\Pool\Channel\FiberChannel;
use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionPool;
use Monadial\Nexus\Doctrine\Dbal\Pool\Evictor;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use Monadial\Nexus\Doctrine\Dbal\Tests\Support\StubConnectionFactory;
use Monadial\Nexus\Runtime\Duration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Evictor::class)]
final class EvictorTest extends TestCase
{
    #[Test]
    public function evictsConnectionsOlderThanIdleTtl(): void
    {
        $factory = new StubConnectionFactory();
        $pool = new ConnectionPool(
            name: 'orders',
            factory: $factory,
            config: new PoolConfig(idleTtl: Duration::nanos(1), max: 3, minIdle: 0),
            channel: new FiberChannel(3),
        );

        $a = $pool->take();
        $pool->release($a);
        self::assertSame(1, $pool->stats()->idle);

        $evictor = new Evictor();
        $evictor->tick($pool, now: hrtime(true) + 1_000_000_000);

        self::assertSame(0, $pool->stats()->idle);
        self::assertSame(0, $pool->stats()->total);
    }

    #[Test]
    public function warmsBackToMinIdleAfterEviction(): void
    {
        $factory = new StubConnectionFactory();
        $pool = new ConnectionPool(
            name: 'orders',
            factory: $factory,
            config: new PoolConfig(idleTtl: Duration::nanos(1), max: 5, minIdle: 2),
            channel: new FiberChannel(5),
        );
        $a = $pool->take();
        $pool->release($a);

        (new Evictor())->tick($pool, now: hrtime(true) + 1_000_000_000);

        self::assertSame(2, $pool->stats()->idle);
        self::assertSame(2, $pool->stats()->total);
    }
}
