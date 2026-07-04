<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Tests\Unit\Inventory\Domain;

use Monadial\Nexus\Example\Fulfillment\Inventory\Domain\InventoryItem;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\Restocked;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\StockReleased;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\StockReservationRejected;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\StockReserved;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\RejectionEvent;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Quantity;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Sku;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Row → test mapping (carries forward every row from InventoryRulesTest and ItemStateTest):
 *
 *  1.  [Rules R1]  restock → [Restocked] always
 *  2.  [Rules R2]  reserve within policy, no existing reservation → [StockReserved]
 *  3.  [Rules R3]  reserve same orderId again → [] (idempotent)
 *  4.  [Rules R4]  reserve > available → [StockReservationRejected] (RejectionEvent)
 *  5.  [Rules R5]  release existing reservation → [StockReleased(original qty)]
 *  6.  [Rules R6]  release non-existent reservation → [] (idempotent)
 *  7.  [State S1]  empty state: onHand=0, reservations=[], reserved()=0, available()=0
 *  8.  [State S2]  apply Restocked increments onHand
 *  9.  [State S3]  apply StockReserved adds to reservations map
 * 10.  [State S4]  apply StockReleased removes from reservations map
 * 11.  [State S5]  apply StockReservationRejected is no-op (state unchanged)
 * 12.  [State S6]  apply unknown event is no-op
 * 13.  [State S7]  reserved() sums all active reservations
 * 14.  [No-double-apply] restock() records but does not apply; state stays pre-command
 */
#[CoversClass(InventoryItem::class)]
final class InventoryItemTest extends TestCase
{
    private TenantId $tenant;
    private Sku $sku;
    private OrderId $orderId;

    protected function setUp(): void
    {
        $this->tenant = TenantId::fromString('acme');
        $this->sku = Sku::fromString('WIDGET-42');
        $this->orderId = OrderId::generate();
    }

    // Row 1 — restock records Restocked always
    #[Test]
    public function restockRecordsRestocked(): void
    {
        $item = InventoryItem::empty($this->tenant, $this->sku);
        $item->restock(Quantity::of(50));
        $events = $item->releaseEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(Restocked::class, $events[0]);
        self::assertSame(50, $events[0]->quantity->value);
    }

    // Row 2 — reserve within policy, no existing reservation → StockReserved
    #[Test]
    public function reserveWhenAvailableRecordsStockReserved(): void
    {
        $item = InventoryItem::empty($this->tenant, $this->sku);
        $item->apply(new Restocked($this->tenant, $this->sku, Quantity::of(10)));

        $item->reserve($this->orderId, Quantity::of(3));
        $events = $item->releaseEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(StockReserved::class, $events[0]);
        self::assertSame(3, $events[0]->quantity->value);
    }

    // Row 3 — reserve same orderId → nothing (idempotent)
    #[Test]
    public function reserveForSameOrderIdRecordsNothing(): void
    {
        $item = InventoryItem::empty($this->tenant, $this->sku);
        $item->apply(new Restocked($this->tenant, $this->sku, Quantity::of(10)));
        $item->apply(new StockReserved($this->tenant, $this->sku, $this->orderId, Quantity::of(3)));

        $item->reserve($this->orderId, Quantity::of(5));

        self::assertSame([], $item->releaseEvents());
    }

    // Row 4 — reserve > available → StockReservationRejected (RejectionEvent)
    #[Test]
    public function reserveMoreThanAvailableRecordsStockReservationRejected(): void
    {
        $item = InventoryItem::empty($this->tenant, $this->sku);
        $item->apply(new Restocked($this->tenant, $this->sku, Quantity::of(2)));

        $item->reserve($this->orderId, Quantity::of(5));
        $events = $item->releaseEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(StockReservationRejected::class, $events[0]);
        self::assertInstanceOf(RejectionEvent::class, $events[0]);
        self::assertSame(5, $events[0]->requested->value);
        self::assertSame(2, $events[0]->available);
        self::assertSame('insufficient stock', $events[0]->reason());
    }

    // Row 5 — release existing reservation → StockReleased(original qty)
    #[Test]
    public function releaseExistingReservationRecordsStockReleased(): void
    {
        $item = InventoryItem::empty($this->tenant, $this->sku);
        $item->apply(new Restocked($this->tenant, $this->sku, Quantity::of(10)));
        $item->apply(new StockReserved($this->tenant, $this->sku, $this->orderId, Quantity::of(3)));

        $item->release($this->orderId);
        $events = $item->releaseEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(StockReleased::class, $events[0]);
        self::assertSame(3, $events[0]->quantity->value);
    }

    // Row 6 — release non-existent reservation → nothing (idempotent)
    #[Test]
    public function releaseNonexistentReservationRecordsNothing(): void
    {
        $item = InventoryItem::empty($this->tenant, $this->sku);
        $item->release($this->orderId);

        self::assertSame([], $item->releaseEvents());
    }

    // Row 7 — empty state
    #[Test]
    public function emptyStateHasZeroOnHandAndNoReservations(): void
    {
        $item = InventoryItem::empty($this->tenant, $this->sku);

        self::assertSame(0, $item->onHand);
        self::assertSame([], $item->reservations);
        self::assertSame(0, $item->reserved());
        self::assertSame(0, $item->available());
    }

    // Row 8 — apply Restocked increments onHand
    #[Test]
    public function applyRestockedIncrementsOnHand(): void
    {
        $item = InventoryItem::empty($this->tenant, $this->sku);
        $item->apply(new Restocked($this->tenant, $this->sku, Quantity::of(20)));

        self::assertSame(20, $item->onHand);
        self::assertSame(0, $item->reserved());
        self::assertSame(20, $item->available());
    }

    // Row 9 — apply StockReserved adds to reservations map
    #[Test]
    public function applyStockReservedAddsToReservationsMap(): void
    {
        $item = InventoryItem::empty($this->tenant, $this->sku);
        $item->apply(new Restocked($this->tenant, $this->sku, Quantity::of(10)));
        $item->apply(new StockReserved($this->tenant, $this->sku, $this->orderId, Quantity::of(3)));

        self::assertSame(10, $item->onHand);
        self::assertSame(3, $item->reserved());
        self::assertSame(7, $item->available());
        self::assertArrayHasKey($this->orderId->value, $item->reservations);
        self::assertSame(3, $item->reservations[$this->orderId->value]);
    }

    // Row 10 — apply StockReleased removes from reservations map
    #[Test]
    public function applyStockReleasedRemovesFromReservationsMap(): void
    {
        $item = InventoryItem::empty($this->tenant, $this->sku);
        $item->apply(new Restocked($this->tenant, $this->sku, Quantity::of(10)));
        $item->apply(new StockReserved($this->tenant, $this->sku, $this->orderId, Quantity::of(3)));
        $item->apply(new StockReleased($this->tenant, $this->sku, $this->orderId, Quantity::of(3)));

        self::assertSame(0, $item->reserved());
        self::assertSame(10, $item->available());
        self::assertArrayNotHasKey($this->orderId->value, $item->reservations);
    }

    // Row 11 — apply StockReservationRejected is no-op
    #[Test]
    public function applyStockReservationRejectedIsNoOp(): void
    {
        $item = InventoryItem::empty($this->tenant, $this->sku);
        $onHandBefore = $item->onHand;
        $reservationsBefore = $item->reservations;

        $item->apply(new StockReservationRejected($this->tenant, $this->sku, $this->orderId, Quantity::of(5), 0, 'insufficient stock'));

        self::assertSame($onHandBefore, $item->onHand);
        self::assertSame($reservationsBefore, $item->reservations);
    }

    // Row 12 — apply unknown event is no-op
    #[Test]
    public function applyUnknownEventIsNoOp(): void
    {
        $item = InventoryItem::empty($this->tenant, $this->sku);
        $item->apply(new stdClass());

        self::assertSame(0, $item->onHand);
        self::assertSame([], $item->reservations);
    }

    // Row 13 — reserved() sums all active reservations
    #[Test]
    public function reservedSumsAllActiveReservations(): void
    {
        $order1 = OrderId::generate();
        $order2 = OrderId::generate();
        $item = InventoryItem::empty($this->tenant, $this->sku);
        $item->apply(new Restocked($this->tenant, $this->sku, Quantity::of(20)));
        $item->apply(new StockReserved($this->tenant, $this->sku, $order1, Quantity::of(4)));
        $item->apply(new StockReserved($this->tenant, $this->sku, $order2, Quantity::of(6)));

        self::assertSame(10, $item->reserved());
        self::assertSame(10, $item->available());
    }

    // Row 14 — no double-apply: restock() records but does not apply; state stays pre-command
    #[Test]
    public function restockRecordsEventWithoutApplyingItSoStateRemainsPreCommand(): void
    {
        $item = InventoryItem::empty($this->tenant, $this->sku);
        $item->restock(Quantity::of(100));

        // State is STILL 0 — record() must not call apply()
        self::assertSame(0, $item->onHand);

        $events = $item->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(Restocked::class, $events[0]);

        // Now simulate the engine fold: apply the event
        $item->apply($events[0]);
        self::assertSame(100, $item->onHand);
    }
}
