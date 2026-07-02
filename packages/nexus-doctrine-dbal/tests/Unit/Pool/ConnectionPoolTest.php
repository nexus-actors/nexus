<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Tests\Unit\Pool;

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

        /** @psalm-suppress RedundantConditionGivenDocblockType */
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
            /** @psalm-suppress UnevaluatedCode */
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
}
