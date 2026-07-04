<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Tests\Unit\Inventory\Domain;

use Monadial\Nexus\Example\Fulfillment\Inventory\Domain\ItemState;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\Restocked;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\StockReleased;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\StockReservationRejected;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\StockReserved;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Quantity;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Sku;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(ItemState::class)]
final class ItemStateTest extends TestCase
{
    #[Test]
    public function emptyStateStartsWithZeroOnHandAndNoReservations(): void
    {
        $state = ItemState::empty(TenantId::fromString('acme'), Sku::fromString('WIDGET-42'));

        self::assertSame(0, $state->onHand);
        self::assertSame([], $state->reservations);
        self::assertSame(0, $state->reserved());
        self::assertSame(0, $state->available());
    }

    #[Test]
    public function evolvingRestockedIncrementsOnHand(): void
    {
        $tenant = TenantId::fromString('acme');
        $sku = Sku::fromString('WIDGET-42');
        $state = ItemState::empty($tenant, $sku);

        $state = ItemState::evolve($state, new Restocked($tenant, $sku, Quantity::of(20)));

        self::assertSame(20, $state->onHand);
        self::assertSame(0, $state->reserved());
        self::assertSame(20, $state->available());
    }

    #[Test]
    public function evolvingStockReservedAddsToReservationsMap(): void
    {
        $tenant = TenantId::fromString('acme');
        $sku = Sku::fromString('WIDGET-42');
        $orderId = OrderId::generate();
        $state = ItemState::empty($tenant, $sku);
        $state = ItemState::evolve($state, new Restocked($tenant, $sku, Quantity::of(10)));

        $state = ItemState::evolve($state, new StockReserved($tenant, $sku, $orderId, Quantity::of(3)));

        self::assertSame(10, $state->onHand);
        self::assertSame(3, $state->reserved());
        self::assertSame(7, $state->available());
        self::assertArrayHasKey($orderId->value, $state->reservations);
        self::assertSame(3, $state->reservations[$orderId->value]);
    }

    #[Test]
    public function evolvingStockReleasedRemovesFromReservationsMap(): void
    {
        $tenant = TenantId::fromString('acme');
        $sku = Sku::fromString('WIDGET-42');
        $orderId = OrderId::generate();
        $state = ItemState::empty($tenant, $sku);
        $state = ItemState::evolve($state, new Restocked($tenant, $sku, Quantity::of(10)));
        $state = ItemState::evolve($state, new StockReserved($tenant, $sku, $orderId, Quantity::of(3)));

        $state = ItemState::evolve($state, new StockReleased($tenant, $sku, $orderId, Quantity::of(3)));

        self::assertSame(0, $state->reserved());
        self::assertSame(10, $state->available());
        self::assertArrayNotHasKey($orderId->value, $state->reservations);
    }

    #[Test]
    public function evolvingStockReservationRejectedIsNoOpSameInstance(): void
    {
        $tenant = TenantId::fromString('acme');
        $sku = Sku::fromString('WIDGET-42');
        $orderId = OrderId::generate();
        $state = ItemState::empty($tenant, $sku);

        // StockReservationRejected is a persisted domain fact; evolve is a no-op
        $next = ItemState::evolve(
            $state,
            new StockReservationRejected($tenant, $sku, $orderId, Quantity::of(5), 0, 'insufficient stock'),
        );

        self::assertSame($state, $next);
    }

    #[Test]
    public function unknownEventsLeaveStateUntouched(): void
    {
        $state = ItemState::empty(TenantId::fromString('acme'), Sku::fromString('WIDGET-42'));

        self::assertSame($state, ItemState::evolve($state, new stdClass()));
    }

    #[Test]
    public function reservedSumsAllActiveReservations(): void
    {
        $tenant = TenantId::fromString('acme');
        $sku = Sku::fromString('WIDGET-42');
        $order1 = OrderId::generate();
        $order2 = OrderId::generate();
        $state = ItemState::empty($tenant, $sku);
        $state = ItemState::evolve($state, new Restocked($tenant, $sku, Quantity::of(20)));
        $state = ItemState::evolve($state, new StockReserved($tenant, $sku, $order1, Quantity::of(4)));
        $state = ItemState::evolve($state, new StockReserved($tenant, $sku, $order2, Quantity::of(6)));

        self::assertSame(10, $state->reserved());
        self::assertSame(10, $state->available());
    }
}
