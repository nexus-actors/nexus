<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Orders\Domain;

use LogicException;
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
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;

use function array_reduce;

/**
 * Order aggregate root. Mutable — apply() mutates state; record() appends events.
 *
 * CRITICAL — no double-apply: record() appends to $recorded and NEVER calls
 * apply(). The persistence engine calls the event handler closure
 * `fn(Order $o, object $e): Order => { $o->apply($e); return $o; }` for each
 * persisted event. Calling apply() inside record() would double-apply every event.
 * Behavior methods therefore read PRE-command state — semantically identical to
 * the old OrderRules::decide().
 *
 * PUBLIC constructor field names and types are identical to the deleted OrderState
 * (tenantId, orderId, status, lines, total, cancelReason) — wire-name continuity:
 * MessageTypes maps 'orders.order_state.v1' to Order so existing snapshots
 * deserialize correctly via Valinor without schema migration.
 *
 * $recorded is private and excluded from Valinor snapshot serialization by design
 * (private fields are not visible to the mapper or json_encode).
 */
final class Order
{
    /** @var list<object> */
    private array $recorded = [];

    /**
     * @param list<OrderLine> $lines
     */
    public function __construct(
        public private(set) TenantId $tenantId,
        public private(set) OrderId $orderId,
        public private(set) OrderStatus $status,
        public private(set) array $lines,
        public private(set) ?Money $total,
        public private(set) ?string $cancelReason,
    ) {}

    public static function empty(TenantId $tenantId, OrderId $orderId): self
    {
        return new self($tenantId, $orderId, OrderStatus::NotCreated, [], null, null);
    }

    /**
     * @param non-empty-list<OrderLine> $lines
     */
    public function place(array $lines): void
    {
        match ($this->status) {
            OrderStatus::NotCreated => $this->record(
                new OrderPlaced($this->tenantId, $this->orderId, $lines, $this->computeTotal($lines)),
            ),
            OrderStatus::Placed,
            OrderStatus::StockReserved => null,
            OrderStatus::Cancelled => $this->record(
                new OrderPlacementRejected($this->tenantId, $this->orderId, 'Order was cancelled; place a new order instead'),
            ),
        };
    }

    public function markStockReserved(): void
    {
        match ($this->status) {
            OrderStatus::Placed => $this->record(new OrderStockReserved($this->tenantId, $this->orderId)),
            OrderStatus::StockReserved => null,
            OrderStatus::NotCreated,
            OrderStatus::Cancelled => $this->record(
                new MarkStockReservedRejected($this->tenantId, $this->orderId, 'Cannot mark stock reserved for order in status ' . $this->status->value),
            ),
        };
    }

    public function cancel(string $reason): void
    {
        match ($this->status) {
            OrderStatus::Placed => $this->record(new OrderCancelled($this->tenantId, $this->orderId, $reason)),
            OrderStatus::Cancelled => null,
            OrderStatus::NotCreated => $this->record(
                new OrderCancellationRejected($this->tenantId, $this->orderId, 'Order does not exist'),
            ),
            OrderStatus::StockReserved => $this->record(
                new OrderCancellationRejected($this->tenantId, $this->orderId, 'cancellation after stock reservation arrives in milestone 4'),
            ),
        };
    }

    /**
     * Drain and return all recorded events. Must be called once per command.
     *
     * @return list<object>
     */
    public function releaseEvents(): array
    {
        $events = $this->recorded;
        $this->recorded = [];

        return $events;
    }

    /**
     * Apply an event — called by the persistence engine's event fold.
     * MUST NOT be called from record() to prevent double-apply.
     */
    public function apply(object $event): void
    {
        match (true) {
            $event instanceof OrderPlaced => $this->applyOrderPlaced($event),
            $event instanceof OrderCancelled => $this->applyOrderCancelled($event),
            $event instanceof OrderStockReserved => $this->applyOrderStockReserved(),
            $event instanceof RejectionEvent => null,
            default => null,
        };
    }

    /**
     * Append an event to $recorded — does NOT apply it.
     */
    private function record(object $event): void
    {
        $this->recorded[] = $event;
    }

    private function applyOrderPlaced(OrderPlaced $event): void
    {
        $this->status = OrderStatus::Placed;
        $this->lines = $event->lines;
        $this->total = $event->total;
        $this->cancelReason = null;
    }

    private function applyOrderCancelled(OrderCancelled $event): void
    {
        $this->status = OrderStatus::Cancelled;
        $this->cancelReason = $event->reason;
    }

    private function applyOrderStockReserved(): void
    {
        $this->status = OrderStatus::StockReserved;
    }

    /**
     * @param non-empty-list<OrderLine> $lines
     */
    private function computeTotal(array $lines): Money
    {
        return array_reduce(
            $lines,
            static fn(?Money $carry, OrderLine $line): Money => $carry === null
                ? $line->total()
                : $carry->add($line->total()),
            null,
        ) ?? throw new LogicException('non-empty-list guarantees at least one line');
    }
}
