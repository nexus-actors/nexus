<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Exception;

use Monadial\Nexus\Ddd\Aggregate\Exception\AggregateAlreadyExistsException;
use Monadial\Nexus\Ddd\Aggregate\Exception\EventNameCollisionException;
use Monadial\Nexus\Ddd\Aggregate\Exception\MultiAggregateTransactionException;
use Monadial\Nexus\Ddd\Aggregate\Exception\UpcasterChainGapException;
use Monadial\Nexus\Ddd\Core\Exception\DomainException;
use Monadial\Nexus\Ddd\Core\Exception\NexusDddException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AggregateAlreadyExistsException::class)]
#[CoversClass(MultiAggregateTransactionException::class)]
#[CoversClass(EventNameCollisionException::class)]
#[CoversClass(UpcasterChainGapException::class)]
final class ExceptionHierarchyTest extends TestCase
{
    #[Test]
    public function aggregateAlreadyExistsExtendsDomainException(): void
    {
        $e = AggregateAlreadyExistsException::for('App\\Order', 'order-1');
        self::assertInstanceOf(DomainException::class, $e);
        self::assertStringContainsString('App\\Order', $e->getMessage());
        self::assertStringContainsString('order-1', $e->getMessage());
    }

    #[Test]
    public function multiAggregateTransactionExtendsNexusDddException(): void
    {
        $e = MultiAggregateTransactionException::secondAggregateInTransaction(
            'App\\Order',
            'order-1',
            'App\\Customer',
            'cust-1',
        );
        self::assertInstanceOf(NexusDddException::class, $e);
    }

    #[Test]
    public function eventNameCollisionExtendsNexusDddException(): void
    {
        $e = EventNameCollisionException::between('orders.OrderPlaced', 'App\\OldOrderPlaced', 'App\\OrderPlaced');
        self::assertInstanceOf(NexusDddException::class, $e);
    }

    #[Test]
    public function upcasterChainGapExtendsNexusDddException(): void
    {
        $e = UpcasterChainGapException::missingUpcaster('orders.OrderPlaced', 1, 2);
        self::assertInstanceOf(NexusDddException::class, $e);
    }
}
