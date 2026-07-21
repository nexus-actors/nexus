<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Tests\Unit\Pool;

use Doctrine\DBAL\Connection;
use Monadial\Nexus\Doctrine\Dbal\Exception\PoolClosedException;
use Monadial\Nexus\Doctrine\Dbal\Exception\PoolExhaustedException;
use Monadial\Nexus\Doctrine\Dbal\Pool\Channel\FiberChannel;
use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionPool;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use Monadial\Nexus\Doctrine\Dbal\Tests\Support\StubConnectionFactory;
use Monadial\Nexus\Runtime\Duration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ConnectionPool::class)]
final class ConnectionPoolTest extends TestCase
{
    #[Test]
    public function takeLazilyCreatesUpToMax(): void
    {
        $factory = new StubConnectionFactory();
        $pool = new ConnectionPool(
            name: 'orders',
            factory: $factory,
            config: new PoolConfig(max: 3, minIdle: 0),
            channel: new FiberChannel(3),
        );

        $a = $pool->take();
        $b = $pool->take();
        $c = $pool->take();

        self::assertSame(3, $factory->creations);
        self::assertSame(3, $pool->stats()->inUse);
        self::assertSame(0, $pool->stats()->idle);
        self::assertSame(3, $pool->stats()->total);
        self::assertSame(3, $pool->stats()->totalBorrows);

        $pool->release($a);
        $pool->release($b);
        $pool->release($c);
    }

    #[Test]
    public function releasedConnectionsAreReused(): void
    {
        $factory = new StubConnectionFactory();
        $pool = new ConnectionPool(
            name: 'orders',
            factory: $factory,
            config: new PoolConfig(max: 2, minIdle: 0),
            channel: new FiberChannel(2),
        );

        $a = $pool->take();
        $pool->release($a);
        $b = $pool->take();

        self::assertSame($a, $b);
        self::assertSame(1, $factory->creations);
        $pool->release($b);
    }

    #[Test]
    public function releaseWithPoisonDestroysConnection(): void
    {
        $factory = new StubConnectionFactory();
        $pool = new ConnectionPool(
            name: 'orders',
            factory: $factory,
            config: new PoolConfig(max: 2, minIdle: 0),
            channel: new FiberChannel(2),
        );

        $a = $pool->take();
        $pool->release($a, poison: true);

        self::assertSame(0, $pool->stats()->total);
        self::assertSame(0, $pool->stats()->idle);

        $b = $pool->take();
        self::assertNotSame($a, $b);
        self::assertSame(2, $factory->creations);
        $pool->release($b);
    }

    #[Test]
    public function withConnectionReleasesOnSuccess(): void
    {
        $factory = new StubConnectionFactory();
        $pool = new ConnectionPool(
            name: 'orders',
            factory: $factory,
            config: new PoolConfig(max: 1, minIdle: 0),
            channel: new FiberChannel(1),
        );

        $result = $pool->withConnection(static fn(): string => 'ok');

        self::assertSame('ok', $result);
        self::assertSame(0, $pool->stats()->inUse);
        self::assertSame(1, $pool->stats()->idle);
    }

    #[Test]
    public function withConnectionPoisonsOnThrow(): void
    {
        $factory = new StubConnectionFactory();
        $pool = new ConnectionPool(
            name: 'orders',
            factory: $factory,
            config: new PoolConfig(max: 1, minIdle: 0),
            channel: new FiberChannel(1),
        );

        try {
            $pool->withConnection(static function (): void {
                throw new RuntimeException('boom');
            });
            self::fail('expected throw');
        } catch (RuntimeException) {
            // expected
        }

        self::assertSame(0, $pool->stats()->total);
        self::assertSame(0, $pool->stats()->idle);
    }

    #[Test]
    public function takeThrowsWhenAtMaxAndChannelEmpty(): void
    {
        $factory = new StubConnectionFactory();
        $pool = new ConnectionPool(
            name: 'orders',
            factory: $factory,
            config: new PoolConfig(borrowTimeout: Duration::millis(1), max: 1, minIdle: 0),
            channel: new FiberChannel(1),
        );

        $held = $pool->take();
        $this->expectException(PoolExhaustedException::class);

        try {
            $pool->take();
        } finally {
            $pool->release($held);
        }
    }

    #[Test]
    public function statsCountTimeouts(): void
    {
        $factory = new StubConnectionFactory();
        $pool = new ConnectionPool(
            name: 'orders',
            factory: $factory,
            config: new PoolConfig(borrowTimeout: Duration::millis(1), max: 1, minIdle: 0),
            channel: new FiberChannel(1),
        );

        $held = $pool->take();

        try {
            $pool->take();
        } catch (PoolExhaustedException) {
            // expected
        }

        self::assertSame(1, $pool->stats()->totalTimeouts);
        self::assertSame(1, $pool->stats()->totalWaits);
        $pool->release($held);
    }

    #[Test]
    public function closeDrainsIdleConnections(): void
    {
        $factory = new StubConnectionFactory();
        $pool = new ConnectionPool(
            name: 'orders',
            factory: $factory,
            config: new PoolConfig(max: 3, minIdle: 0),
            channel: new FiberChannel(3),
        );

        $a = $pool->take();
        $b = $pool->take();
        $pool->release($a);
        $pool->release($b);

        $pool->close(Duration::seconds(1));

        self::assertSame(0, $pool->stats()->idle);
        self::assertSame(0, $pool->stats()->total);
    }

    #[Test]
    public function takeAfterCloseThrows(): void
    {
        $pool = new ConnectionPool(
            name: 'orders',
            factory: new StubConnectionFactory(),
            config: new PoolConfig(max: 1, minIdle: 0),
            channel: new FiberChannel(1),
        );
        $pool->close(Duration::seconds(1));

        $this->expectException(PoolClosedException::class);
        $pool->take();
    }

    // ========================================================================
    // Connections are sanitized before reuse (SEC-005)
    // ========================================================================

    #[Test]
    public function releaseRollsBackAnActiveTransactionBeforeRequeue(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('isTransactionActive')->willReturn(true);
        $conn->expects(self::once())->method('rollBack');

        $factory = new StubConnectionFactory();
        $factory->prepend($conn);

        $pool = new ConnectionPool(
            name: 'tenant-a',
            factory: $factory,
            config: new PoolConfig(max: 1, minIdle: 0),
            channel: new FiberChannel(1),
        );

        $borrowed = $pool->take();
        $pool->release($borrowed);

        // Sanitized, not poisoned: returned to the idle pool for reuse.
        self::assertSame(1, $pool->stats()->idle);
        self::assertSame(1, $pool->stats()->total);
    }

    #[Test]
    public function releaseRunsTheConfiguredResetQuery(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('isTransactionActive')->willReturn(false);
        $conn->expects(self::once())
            ->method('executeStatement')
            ->with('DISCARD ALL')
            ->willReturn(0);

        $factory = new StubConnectionFactory();
        $factory->prepend($conn);

        $pool = new ConnectionPool(
            name: 'tenant-a',
            factory: $factory,
            config: new PoolConfig(max: 1, minIdle: 0, resetQuery: 'DISCARD ALL'),
            channel: new FiberChannel(1),
        );

        $pool->release($pool->take());

        self::assertSame(1, $pool->stats()->idle);
    }

    #[Test]
    public function releasePoisonsWhenRollbackFails(): void
    {
        $conn = $this->createStub(Connection::class);
        $conn->method('isTransactionActive')->willReturn(true);
        $conn->method('rollBack')->willThrowException(new RuntimeException('rollback failed'));

        $factory = new StubConnectionFactory();
        $factory->prepend($conn);

        $pool = new ConnectionPool(
            name: 'tenant-a',
            factory: $factory,
            config: new PoolConfig(max: 2, minIdle: 0),
            channel: new FiberChannel(2),
        );

        $pool->release($pool->take());

        // Cleanup failed → connection discarded, never returned to the pool.
        self::assertSame(0, $pool->stats()->idle);
        self::assertSame(0, $pool->stats()->total);
    }

    #[Test]
    public function releasePoisonsWhenResetQueryFails(): void
    {
        $conn = $this->createStub(Connection::class);
        $conn->method('isTransactionActive')->willReturn(false);
        $conn->method('executeStatement')->willThrowException(new RuntimeException('reset failed'));

        $factory = new StubConnectionFactory();
        $factory->prepend($conn);

        $pool = new ConnectionPool(
            name: 'tenant-a',
            factory: $factory,
            config: new PoolConfig(max: 1, minIdle: 0, resetQuery: 'DISCARD ALL'),
            channel: new FiberChannel(1),
        );

        $pool->release($pool->take());

        self::assertSame(0, $pool->stats()->idle);
        self::assertSame(0, $pool->stats()->total);
    }

    #[Test]
    public function cleanReleaseDoesNotRollBackAndIsReused(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('isTransactionActive')->willReturn(false);
        $conn->expects(self::never())->method('rollBack');

        $factory = new StubConnectionFactory();
        $factory->prepend($conn);

        $pool = new ConnectionPool(
            name: 'tenant-a',
            factory: $factory,
            config: new PoolConfig(max: 1, minIdle: 0),
            channel: new FiberChannel(1),
        );

        $pool->release($pool->take());

        self::assertSame(1, $pool->stats()->idle);
        self::assertSame($conn, $pool->take());
    }

    #[Test]
    public function sequentialTenantBorrowsGetSanitizedConnections(): void
    {
        // Tenant A leaves an open transaction and dirty session state; on
        // release it is rolled back and reset. Tenant B (same pool, next
        // borrow) receives the same physical connection already sanitized.
        $conn = $this->createMock(Connection::class);
        $conn->method('isTransactionActive')->willReturn(true);
        $conn->expects(self::once())->method('rollBack');
        $conn->expects(self::once())
            ->method('executeStatement')
            ->with('RESET ALL')
            ->willReturn(0);

        $factory = new StubConnectionFactory();
        $factory->prepend($conn);

        $pool = new ConnectionPool(
            name: 'shared',
            factory: $factory,
            config: new PoolConfig(max: 1, minIdle: 0, resetQuery: 'RESET ALL'),
            channel: new FiberChannel(1),
        );

        $tenantA = $pool->take();
        $pool->release($tenantA); // sanitized here (rollBack + RESET ALL)

        $tenantB = $pool->take(); // reuses the sanitized connection

        self::assertSame($conn, $tenantB);
        self::assertSame(1, $factory->creations, 'connection reused, not recreated');
        // tenantB left borrowed: asserting a single sanitize on the A→B handover.
    }
}
