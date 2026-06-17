<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Unit\Http;

use Doctrine\ORM\EntityManagerInterface;
use Monadial\Nexus\Doctrine\Dbal\Pool\Channel\FiberChannel;
use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionPool;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use Monadial\Nexus\Doctrine\Dbal\Tests\Support\StubConnectionFactory;
use Monadial\Nexus\Doctrine\Orm\Http\EntityManagerLease;
use Monadial\Nexus\Doctrine\Orm\Pool\EmPoolConfig;
use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerPool;
use Monadial\Nexus\Doctrine\Orm\Tests\Support\StubEntityManagerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EntityManagerLease::class)]
final class EntityManagerLeaseTest extends TestCase
{
    #[Test]
    public function getLazilyBorrows(): void
    {
        $pool = $this->pool();
        $lease = new EntityManagerLease($pool);
        self::assertSame(0, $pool->stats()->inUse);

        $a = $lease->get();
        self::assertInstanceOf(EntityManagerInterface::class, $a);
        self::assertSame(1, $pool->stats()->inUse);

        $b = $lease->get();
        self::assertSame($a, $b);

        $lease->release();
        self::assertSame(0, $pool->stats()->inUse);
    }

    #[Test]
    public function releaseWithoutGetIsNoOp(): void
    {
        $pool = $this->pool();
        (new EntityManagerLease($pool))->release();
        self::assertSame(0, $pool->stats()->inUse);
    }

    /** @psalm-suppress ArgumentTypeCoercion */
    private function pool(): EntityManagerPool
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('isOpen')->willReturn(true);

        $emFactory = new StubEntityManagerFactory();
        $emFactory->prepend($em);

        $connPool = new ConnectionPool(
            name: 'em-private',
            factory: new StubConnectionFactory(),
            config: new PoolConfig(max: 1, minIdle: 0),
            channel: new FiberChannel(1),
        );

        return new EntityManagerPool(
            name: 'orders',
            factory: $emFactory,
            connPool: $connPool,
            config: new EmPoolConfig(max: 1, minIdle: 0),
            channel: new FiberChannel(1),
        );
    }
}
