<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Tests\Unit\Orders\Domain;

use Monadial\Nexus\Example\Fulfillment\Orders\Domain\Order;
use Monadial\Nexus\Example\Fulfillment\Orders\Domain\OrderStatus;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\MarkStockReservedRejected;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\OrderCancellationRejected;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\OrderCancelled;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\OrderPlaced;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\OrderPlacementRejected;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\OrderStockReserved;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\RejectionEvent;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Money;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderLine;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Quantity;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Sku;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Row → test mapping (carries forward every row from OrderRulesTest and OrderStateTest):
 *
 *  1. [Rules R1] place on NotCreated → OrderPlaced (total computed)
 *  2. [Rules R2] place on Placed → nothing (idempotent)
 *  3. [Rules R3] place on StockReserved → nothing (idempotent)
 *  4. [Rules R4] place on Cancelled → OrderPlacementRejected (RejectionEvent)
 *  5. [Rules R5] cancel on Placed → OrderCancelled
 *  6. [Rules R6] cancel on Cancelled → nothing (idempotent)
 *  7. [Rules R7] cancel on NotCreated → OrderCancellationRejected (RejectionEvent)
 *  8. [Rules R8] cancel on StockReserved → OrderCancellationRejected (RejectionEvent)
 *  9. [Rules R9] markStockReserved on Placed → OrderStockReserved
 * 10. [Rules R10] markStockReserved on StockReserved → nothing (idempotent)
 * 11. [Rules R11] markStockReserved on NotCreated → MarkStockReservedRejected (RejectionEvent)
 * 12. [Rules R12] markStockReserved on Cancelled → MarkStockReservedRejected (RejectionEvent)
 * 13. [State S1] empty state has NotCreated status, null total
 * 14. [State S2] apply lifecycle: placed → cancelled transitions state correctly
 * 15. [State S3] apply OrderStockReserved transitions to StockReserved
 * 16. [State S4] apply unknown event is no-op
 * 17. [No-double-apply] place() does not apply events; state is pre-command until engine fold
 * 18. [Invariant I1] place on NotCreated with duplicate SKUs → OrderPlacementRejected (no OrderPlaced)
 */
#[CoversClass(Order::class)]
final class OrderTest extends TestCase
{
    private TenantId $tenant;
    private OrderId $orderId;

    /** @var non-empty-list<OrderLine> */
    private array $lines;

    protected function setUp(): void
    {
        $this->tenant = TenantId::fromString('acme');
        $this->orderId = OrderId::generate();
        $this->lines = [
            new OrderLine(Sku::fromString('WIDGET-42'), Quantity::of(2), Money::of(1999, 'EUR')),
        ];
    }

    // Row 1 — place on NotCreated → OrderPlaced with computed total
    #[Test]
    public function placeOnNotCreatedRecordsOrderPlacedWithComputedTotal(): void
    {
        $order = $this->emptyOrder();
        $order->place($this->lines);
        $events = $order->releaseEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(OrderPlaced::class, $events[0]);
        self::assertTrue($events[0]->total->equals(Money::of(3998, 'EUR')));
    }

    // Row 2 — place on Placed → nothing (idempotent)
    #[Test]
    public function placeOnPlacedRecordsNothing(): void
    {
        $order = $this->emptyOrder();
        $order->apply(new OrderPlaced($this->tenant, $this->orderId, $this->lines, Money::of(3998, 'EUR')));
        $order->place($this->lines);

        self::assertSame([], $order->releaseEvents());
    }

    // Row 3 — place on StockReserved → nothing (idempotent)
    #[Test]
    public function placeOnStockReservedRecordsNothing(): void
    {
        $order = $this->stockReservedOrder();
        $order->place($this->lines);

        self::assertSame([], $order->releaseEvents());
    }

    // Row 4 — place on Cancelled → OrderPlacementRejected (RejectionEvent)
    #[Test]
    public function placeOnCancelledRecordsOrderPlacementRejected(): void
    {
        $order = $this->cancelledOrder();
        $order->place($this->lines);
        $events = $order->releaseEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(OrderPlacementRejected::class, $events[0]);
        self::assertInstanceOf(RejectionEvent::class, $events[0]);
        self::assertStringContainsString('cancelled', $events[0]->reason());
    }

    // Row 5 — cancel on Placed → OrderCancelled
    #[Test]
    public function cancelOnPlacedRecordsOrderCancelled(): void
    {
        $order = $this->emptyOrder();
        $order->apply(new OrderPlaced($this->tenant, $this->orderId, $this->lines, Money::of(3998, 'EUR')));
        $order->cancel('customer request');
        $events = $order->releaseEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(OrderCancelled::class, $events[0]);
        self::assertSame('customer request', $events[0]->reason);
    }

    // Row 6 — cancel on Cancelled → nothing (idempotent)
    #[Test]
    public function cancelOnCancelledRecordsNothing(): void
    {
        $order = $this->cancelledOrder();
        $order->cancel('again');

        self::assertSame([], $order->releaseEvents());
    }

    // Row 7 — cancel on NotCreated → OrderCancellationRejected (RejectionEvent)
    #[Test]
    public function cancelOnNotCreatedRecordsOrderCancellationRejected(): void
    {
        $order = $this->emptyOrder();
        $order->cancel('nope');
        $events = $order->releaseEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(OrderCancellationRejected::class, $events[0]);
        self::assertInstanceOf(RejectionEvent::class, $events[0]);
        self::assertSame('Order does not exist', $events[0]->reason());
    }

    // Row 8 — cancel on StockReserved → OrderCancellationRejected (RejectionEvent)
    #[Test]
    public function cancelOnStockReservedRecordsOrderCancellationRejected(): void
    {
        $order = $this->stockReservedOrder();
        $order->cancel('too late');
        $events = $order->releaseEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(OrderCancellationRejected::class, $events[0]);
        self::assertInstanceOf(RejectionEvent::class, $events[0]);
        self::assertStringContainsString('milestone 4', $events[0]->reason());
    }

    // Row 9 — markStockReserved on Placed → OrderStockReserved
    #[Test]
    public function markStockReservedOnPlacedRecordsOrderStockReserved(): void
    {
        $order = $this->emptyOrder();
        $order->apply(new OrderPlaced($this->tenant, $this->orderId, $this->lines, Money::of(3998, 'EUR')));
        $order->markStockReserved();
        $events = $order->releaseEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(OrderStockReserved::class, $events[0]);
    }

    // Row 10 — markStockReserved on StockReserved → nothing (idempotent)
    #[Test]
    public function markStockReservedOnStockReservedRecordsNothing(): void
    {
        $order = $this->stockReservedOrder();
        $order->markStockReserved();

        self::assertSame([], $order->releaseEvents());
    }

    // Row 11 — markStockReserved on NotCreated → MarkStockReservedRejected (RejectionEvent)
    #[Test]
    public function markStockReservedOnNotCreatedRecordsMarkStockReservedRejected(): void
    {
        $order = $this->emptyOrder();
        $order->markStockReserved();
        $events = $order->releaseEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(MarkStockReservedRejected::class, $events[0]);
        self::assertInstanceOf(RejectionEvent::class, $events[0]);
    }

    // Row 12 — markStockReserved on Cancelled → MarkStockReservedRejected (RejectionEvent)
    #[Test]
    public function markStockReservedOnCancelledRecordsMarkStockReservedRejected(): void
    {
        $order = $this->cancelledOrder();
        $order->markStockReserved();
        $events = $order->releaseEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(MarkStockReservedRejected::class, $events[0]);
        self::assertInstanceOf(RejectionEvent::class, $events[0]);
    }

    // Row 13 — empty starts NotCreated with null total
    #[Test]
    public function emptyOrderStartsNotCreatedWithNullTotal(): void
    {
        $order = $this->emptyOrder();

        self::assertSame(OrderStatus::NotCreated, $order->status);
        self::assertNull($order->total);
        self::assertSame([], $order->lines);
    }

    // Row 14 — apply lifecycle: Placed → Cancelled transitions status
    #[Test]
    public function applyLifecycleTransitionsStatusCorrectly(): void
    {
        $order = $this->emptyOrder();

        $order->apply(new OrderPlaced($this->tenant, $this->orderId, $this->lines, Money::of(3998, 'EUR')));
        self::assertSame(OrderStatus::Placed, $order->status);
        self::assertTrue($order->total?->equals(Money::of(3998, 'EUR')));

        $order->apply(new OrderCancelled($this->tenant, $this->orderId, 'why not'));
        self::assertSame(OrderStatus::Cancelled, $order->status);
        self::assertSame('why not', $order->cancelReason);
    }

    // Row 15 — apply OrderStockReserved transitions to StockReserved
    #[Test]
    public function applyOrderStockReservedTransitionsToStockReservedStatus(): void
    {
        $order = $this->emptyOrder();
        $order->apply(new OrderPlaced($this->tenant, $this->orderId, $this->lines, Money::of(3998, 'EUR')));
        $order->apply(new OrderStockReserved($this->tenant, $this->orderId));

        self::assertSame(OrderStatus::StockReserved, $order->status);
        self::assertSame($this->lines, $order->lines);
        self::assertTrue($order->total?->equals(Money::of(3998, 'EUR')));
        self::assertNull($order->cancelReason);
    }

    // Row 16 — apply unknown event is no-op
    #[Test]
    public function applyUnknownEventIsNoOp(): void
    {
        $order = $this->emptyOrder();
        $order->apply(new stdClass());

        self::assertSame(OrderStatus::NotCreated, $order->status);
    }

    // Row 17 — no double-apply: place() records but does not apply; state stays pre-command
    #[Test]
    public function placeRecordsEventWithoutApplyingItSoStateRemainsPreCommand(): void
    {
        $order = $this->emptyOrder();
        $order->place($this->lines);

        // State is STILL NotCreated — record() must not call apply()
        self::assertSame(OrderStatus::NotCreated, $order->status);

        $events = $order->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(OrderPlaced::class, $events[0]);

        // Now simulate the engine fold: apply the event
        $order->apply($events[0]);
        self::assertSame(OrderStatus::Placed, $order->status);
    }

    // Row 18 — place on NotCreated with duplicate SKUs → OrderPlacementRejected, no OrderPlaced
    #[Test]
    public function placeOnNotCreatedWithDuplicateSkusRecordsOrderPlacementRejected(): void
    {
        $order = $this->emptyOrder();
        $order->place([
            new OrderLine(Sku::fromString('WIDGET-42'), Quantity::of(2), Money::of(1999, 'EUR')),
            new OrderLine(Sku::fromString('WIDGET-42'), Quantity::of(1), Money::of(1999, 'EUR')),
        ]);
        $events = $order->releaseEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(OrderPlacementRejected::class, $events[0]);
        self::assertInstanceOf(RejectionEvent::class, $events[0]);
        self::assertStringContainsString('duplicate SKU', $events[0]->reason());
        self::assertStringContainsString('WIDGET-42', $events[0]->reason());
    }

    // Rejection events are no-ops in apply()
    #[Test]
    public function applyRejectionEventIsNoOp(): void
    {
        $order = $this->cancelledOrder();
        $statusBefore = $order->status;

        $order->apply(new OrderPlacementRejected($this->tenant, $this->orderId, 'some reason'));

        self::assertSame($statusBefore, $order->status);
    }

    private function emptyOrder(): Order
    {
        return Order::empty($this->tenant, $this->orderId);
    }

    private function cancelledOrder(): Order
    {
        $order = $this->emptyOrder();
        $order->apply(new OrderPlaced($this->tenant, $this->orderId, $this->lines, Money::of(3998, 'EUR')));
        $order->apply(new OrderCancelled($this->tenant, $this->orderId, 'x'));

        return $order;
    }

    private function stockReservedOrder(): Order
    {
        $order = $this->emptyOrder();
        $order->apply(new OrderPlaced($this->tenant, $this->orderId, $this->lines, Money::of(3998, 'EUR')));
        $order->apply(new OrderStockReserved($this->tenant, $this->orderId));

        return $order;
    }
}
