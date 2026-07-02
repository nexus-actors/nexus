<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Tests\Unit\Http;

use Monadial\Nexus\Doctrine\Dbal\Http\ConnectionLease;
use Monadial\Nexus\Doctrine\Dbal\Pool\Channel\FiberChannel;
use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionPool;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use Monadial\Nexus\Doctrine\Dbal\Tests\Support\StubConnectionFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConnectionLease::class)]
final class ConnectionLeaseTest extends TestCase
{
    #[Test]
    public function getLazilyBorrowsFromPool(): void
    {
        $pool = $this->pool(new StubConnectionFactory());
        $lease = new ConnectionLease($pool);

        self::assertSame(0, $pool->stats()->inUse);

        $conn = $lease->get();
        self::assertSame(1, $pool->stats()->inUse);

        $conn2 = $lease->get();
        self::assertSame($conn, $conn2);
        self::assertSame(1, $pool->stats()->inUse);

        $lease->release();
        self::assertSame(0, $pool->stats()->inUse);
    }

    #[Test]
    public function releaseWithoutGetIsNoOp(): void
    {
        $pool = $this->pool(new StubConnectionFactory());

        (new ConnectionLease($pool))->release();
        self::assertSame(0, $pool->stats()->inUse);
    }

    #[Test]
    public function poisonFlagPersistsThroughRelease(): void
    {
        $pool = $this->pool(new StubConnectionFactory());
        $lease = new ConnectionLease($pool);

        $lease->get();
        $lease->poison();
        $lease->release();

        self::assertSame(0, $pool->stats()->total);
    }

    private function pool(StubConnectionFactory $factory): ConnectionPool
    {
        return new ConnectionPool(
            name: 'orders',
            factory: $factory,
            config: new PoolConfig(max: 2, minIdle: 0),
            channel: new FiberChannel(2),
        );
    }
}
