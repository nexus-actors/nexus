<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Inventory\Domain;

use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\Restocked;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\StockReleased;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\StockReservationRejected;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\StockReserved;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Quantity;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Sku;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;

use function array_diff_key;
use function array_merge;
use function array_sum;

/**
 * InventoryItem aggregate root. Mutable — apply() mutates state; record() appends events.
 *
 * CRITICAL — no double-apply: record() appends to $recorded and NEVER calls
 * apply(). The persistence engine calls the event handler closure
 * `fn(InventoryItem $i, object $e): InventoryItem => { $i->apply($e); return $i; }` for each
 * persisted event. Self-applying inside record() would double-apply every event.
 *
 * PUBLIC constructor field names and types are identical to the deleted ItemState
 * (tenantId, sku, onHand, reservations) — wire-name continuity:
 * MessageTypes maps 'inventory.item_state.v1' to InventoryItem so existing snapshots
 * deserialize correctly via Valinor without schema migration.
 *
 * $recorded is private and excluded from Valinor snapshot serialization by design.
 */
final class InventoryItem
{
    /** @var list<object> */
    private array $recorded = [];

    /**
     * @param array<string, int> $reservations orderId->qty map
     */
    public function __construct(
        public private(set) TenantId $tenantId,
        public private(set) Sku $sku,
        public private(set) int $onHand,
        public private(set) array $reservations,
    ) {}

    public static function empty(TenantId $tenantId, Sku $sku): self
    {
        return new self($tenantId, $sku, 0, []);
    }

    /**
     * Sum of all active reservation quantities.
     */
    public function reserved(): int
    {
        return array_sum($this->reservations);
    }

    /**
     * On-hand stock minus all active reservations.
     */
    public function available(): int
    {
        return $this->onHand - $this->reserved();
    }

    public function restock(Quantity $quantity): void
    {
        $this->record(new Restocked($this->tenantId, $this->sku, $quantity));
    }

    public function reserve(OrderId $orderId, Quantity $quantity): void
    {
        if (isset($this->reservations[$orderId->value])) {
            return; // idempotent — reservation already exists for this order
        }

        if (!ReservationPolicy::allows($this, $quantity)) {
            $this->record(new StockReservationRejected(
                $this->tenantId,
                $this->sku,
                $orderId,
                $quantity,
                $this->available(),
                'insufficient stock',
            ));

            return;
        }

        $this->record(new StockReserved($this->tenantId, $this->sku, $orderId, $quantity));
    }

    public function release(OrderId $orderId): void
    {
        if (!isset($this->reservations[$orderId->value])) {
            return; // idempotent — no reservation to release
        }

        $this->record(new StockReleased(
            $this->tenantId,
            $this->sku,
            $orderId,
            Quantity::of($this->reservations[$orderId->value]),
        ));
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
            $event instanceof Restocked => $this->onHand += $event->quantity->value,
            $event instanceof StockReserved => $this->reservations = array_merge(
                $this->reservations,
                [$event->orderId->value => $event->quantity->value],
            ),
            $event instanceof StockReleased => $this->reservations = array_diff_key(
                $this->reservations,
                [$event->orderId->value => 0],
            ),
            // StockReservationRejected and unknown events are no-ops: state unchanged
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
}
