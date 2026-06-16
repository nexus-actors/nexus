<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Tests\Unit\Exception;

use Monadial\Nexus\Core\Exception\NexusException;
use Monadial\Nexus\Doctrine\Dbal\Exception\ConnectionPoisonedException;
use Monadial\Nexus\Doctrine\Dbal\Exception\MissingConnectionScopeException;
use Monadial\Nexus\Doctrine\Dbal\Exception\MissingTransactionalDependencyException;
use Monadial\Nexus\Doctrine\Dbal\Exception\PoolClosedException;
use Monadial\Nexus\Doctrine\Dbal\Exception\PoolExhaustedException;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolStats;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PoolExhaustedException::class)]
#[CoversClass(PoolClosedException::class)]
#[CoversClass(ConnectionPoisonedException::class)]
#[CoversClass(MissingConnectionScopeException::class)]
#[CoversClass(MissingTransactionalDependencyException::class)]
final class ExceptionHierarchyTest extends TestCase
{
    #[Test]
    public function poolExhaustedCarriesStats(): void
    {
        $stats = PoolStats::empty();
        $e = PoolExhaustedException::after('orders', $stats);

        self::assertInstanceOf(NexusException::class, $e);
        self::assertSame($stats, $e->stats);
        self::assertSame('orders', $e->poolName);
        self::assertStringContainsString('orders', $e->getMessage());
    }

    #[Test]
    public function poolClosedExtendsNexus(): void
    {
        self::assertInstanceOf(NexusException::class, new PoolClosedException('orders'));
    }

    #[Test]
    public function connectionPoisonedExtendsNexus(): void
    {
        self::assertInstanceOf(NexusException::class, new ConnectionPoisonedException('cause'));
    }

    #[Test]
    public function missingConnectionScopeMessageHintsAtMiddleware(): void
    {
        $e = new MissingConnectionScopeException();
        self::assertStringContainsString('ConnectionScopeMiddleware', $e->getMessage());
    }

    #[Test]
    public function missingTransactionalDependencyMessage(): void
    {
        $e = MissingTransactionalDependencyException::onHandler('App\\CreateOrder');
        self::assertStringContainsString('App\\CreateOrder', $e->getMessage());
    }
}
