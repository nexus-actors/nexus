<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Inventory\Domain;

use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\Restocked;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\StockReleased;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\StockReservationRejected;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\StockReserved;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Sku;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;

use function array_diff_key;
use function array_merge;
use function array_sum;

/**
 * EVOLVE: the fold of the Inventory event log. Pure — no clock, no I/O.
 *
 * Public constructor is required for Valinor snapshot deserialization
 * (registered as 'inventory.item_state.v1' in MessageTypes).
 */
final readonly class ItemState
{
    /**
     * @param array<string, int> $reservations orderId->qty map
     */
    public function __construct(
        public TenantId $tenantId,
        public Sku $sku,
        public int $onHand,
        public array $reservations,
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

    public static function evolve(self $state, object $event): self
    {
        return match (true) {
            $event instanceof Restocked => new self(
                $state->tenantId,
                $state->sku,
                $state->onHand + $event->quantity->value,
                $state->reservations,
            ),
            $event instanceof StockReserved => new self(
                $state->tenantId,
                $state->sku,
                $state->onHand,
                array_merge($state->reservations, [$event->orderId->value => $event->quantity->value]),
            ),
            $event instanceof StockReleased => new self(
                $state->tenantId,
                $state->sku,
                $state->onHand,
                array_diff_key($state->reservations, [$event->orderId->value => 0]),
            ),
            // Persisted domain fact — rejection is recorded for audit; no state change.
            $event instanceof StockReservationRejected => $state,
            default => $state,
        };
    }
}
