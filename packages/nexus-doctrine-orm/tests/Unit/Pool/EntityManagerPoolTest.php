<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Unit\Pool;

use Doctrine\ORM\EntityManagerInterface;
use Monadial\Nexus\Doctrine\Dbal\Exception\PoolExhaustedException;
use Monadial\Nexus\Doctrine\Dbal\Pool\Channel\FiberChannel;
use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionPool;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use Monadial\Nexus\Doctrine\Dbal\Tests\Support\StubConnectionFactory;
use Monadial\Nexus\Doctrine\Orm\Pool\EmPoolConfig;
use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerPool;
use Monadial\Nexus\Doctrine\Orm\Pool\PooledEntityManager;
use Monadial\Nexus\Doctrine\Orm\Tests\Support\StubEntityManagerFactory;
use Monadial\Nexus\Runtime\Duration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EntityManagerPool::class)]
final class EntityManagerPoolTest extends TestCase
{
    #[Test]
    public function takeLazilyConstructsUpToMax(): void
    {
        $emFactory = new StubEntityManagerFactory();
        $emFactory->prepend($this->openEm());
        $emFactory->prepend($this->openEm());

        $pool = $this->pool($emFactory, new EmPoolConfig(max: 2, minIdle: 0));

        $a = $pool->take();
        $b = $pool->take();

        self::assertInstanceOf(PooledEntityManager::class, $a);
        self::assertInstanceOf(PooledEntityManager::class, $b);
        self::assertSame(2, $emFactory->creations);
        self::assertSame(2, $pool->stats()->inUse);

        $pool->release($a);
        $pool->release($b);
    }

    #[Test]
    public function releaseClearsAndReuses(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('isOpen')->willReturn(true);
        $em->expects(self::once())->method('clear');

        $emFactory = new StubEntityManagerFactory();
        $emFactory->prepend($em);
        $pool = $this->pool($emFactory, new EmPoolConfig(clearOnReturn: true, max: 1, minIdle: 0));

        $a = $pool->take();
        $pool->release($a);
        $b = $pool->take();

        self::assertSame($a, $b);
        self::assertSame(1, $emFactory->creations);
        $pool->release($b);
    }

    #[Test]
    public function closedEmIsEvicted(): void
    {
        $closedEm = $this->createMock(EntityManagerInterface::class);
        $closedEm->method('isOpen')->willReturn(false);

        $emFactory = new StubEntityManagerFactory();
        $emFactory->prepend($closedEm);
        $emFactory->prepend($this->openEm());

        $pool = $this->pool($emFactory, new EmPoolConfig(max: 1, minIdle: 0));

        $a = $pool->take();
        $pool->release($a);
        self::assertSame(0, $pool->stats()->total);
        self::assertSame(1, $pool->stats()->totalEvictions);

        $b = $pool->take();
        self::assertNotSame($a, $b);
        $pool->release($b);
    }

    #[Test]
    public function withEntityManagerReleasesOnSuccess(): void
    {
        $emFactory = new StubEntityManagerFactory();
        $emFactory->prepend($this->openEm());
        $pool = $this->pool($emFactory, new EmPoolConfig(max: 1, minIdle: 0));

        $result = $pool->withEntityManager(static fn(EntityManagerInterface $em): string => 'ok');

        self::assertSame('ok', $result);
        self::assertSame(0, $pool->stats()->inUse);
    }

    #[Test]
    public function recreateAfterDestroysAtThreshold(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('isOpen')->willReturn(true);

        $emFactory = new StubEntityManagerFactory();
        $emFactory->prepend($em);
        $emFactory->prepend($this->openEm());

        $pool = $this->pool($emFactory, new EmPoolConfig(max: 1, minIdle: 0, recreateAfter: 2));

        $a = $pool->take();    // borrowCount = 1
        $pool->release($a);
        $b = $pool->take();    // borrowCount = 2 — reaches threshold, will be destroyed on release
        self::assertSame($a, $b);
        $pool->release($b);
        self::assertSame(1, $pool->stats()->totalEvictions);

        $c = $pool->take();    // fresh EM
        self::assertNotSame($a, $c);
        $pool->release($c);
    }

    #[Test]
    public function exhaustionStatsTrackWaitAndTimeout(): void
    {
        $emFactory = new StubEntityManagerFactory();
        $emFactory->prepend($this->openEm());

        $pool = $this->pool($emFactory, new EmPoolConfig(
            borrowTimeout: Duration::millis(10),
            max: 1,
            minIdle: 0,
        ));

        $borrowed = $pool->take();

        try {
            $pool->take();
            self::fail('expected PoolExhaustedException');
        } catch (PoolExhaustedException $e) {
            self::assertSame(1, $e->stats->totalWaits);
            self::assertSame(1, $e->stats->totalTimeouts);
            self::assertSame(0, $e->stats->waitingCoroutines);
        }

        $stats = $pool->stats();
        self::assertSame(1, $stats->totalWaits);
        self::assertSame(1, $stats->totalTimeouts);
        self::assertSame(0, $stats->waitingCoroutines);

        $pool->release($borrowed);
    }

    private function openEm(): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('isOpen')->willReturn(true);

        return $em;
    }

    private function pool(StubEntityManagerFactory $emFactory, EmPoolConfig $config): EntityManagerPool
    {
        $connPool = new ConnectionPool(
            name: 'em-private',
            factory: new StubConnectionFactory(),
            config: new PoolConfig(max: $config->max, minIdle: 0),
            channel: new FiberChannel($config->max),
        );

        return new EntityManagerPool(
            name: 'orders',
            factory: $emFactory,
            connPool: $connPool,
            config: $config,
            channel: new FiberChannel($config->max),
        );
    }
}
