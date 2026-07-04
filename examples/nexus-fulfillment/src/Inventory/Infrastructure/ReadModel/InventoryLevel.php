<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Inventory\Infrastructure\ReadModel;

use DateTimeImmutable;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\Restocked;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\StockReleased;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\StockReserved;

/**
 * CQRS read side: a flat, indexed row per (tenant, sku) stock level.
 * Derived state — the event journal is the source of truth; this table is
 * rebuildable.
 *
 * `$reservations` (orderId->qty) is tracked so reserve/release folds are
 * idempotent under projection rebuild replays and the future at-least-once
 * broker delivery; `reserved` mirrors the domain aggregate's derivation.
 * Restock has no idempotency key and increments on_hand — consistent with the
 * domain and the journal, which never emit a duplicate restock event.
 */
#[Entity]
#[Table(name: 'inventory_levels')]
final class InventoryLevel
{
    #[Column(name: 'on_hand')]
    public private(set) int $onHand = 0;

    #[Column]
    public private(set) int $reserved = 0;

    /**
     * @var array<string, int> orderId->qty map
     */
    #[Column(type: 'json')]
    public private(set) array $reservations = [];

    #[Column(name: 'updated_at', type: 'datetime_immutable')]
    public private(set) DateTimeImmutable $updatedAt;

    public function __construct(
        #[Id]
        #[Column(name: 'tenant_id')]
        public private(set) string $tenantId,
        #[Id]
        #[Column]
        public private(set) string $sku,
    ) {
        $this->updatedAt = new DateTimeImmutable();
    }

    public function applyRestocked(Restocked $event): void
    {
        $this->onHand += $event->quantity->value;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function applyReserved(StockReserved $event): void
    {
        if (isset($this->reservations[$event->orderId->value])) {
            return; // idempotent — reservation already folded for this order
        }

        $this->reservations[$event->orderId->value] = $event->quantity->value;
        $this->reserved += $event->quantity->value;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function applyReleased(StockReleased $event): void
    {
        if (!isset($this->reservations[$event->orderId->value])) {
            return; // idempotent — nothing to release for this order
        }

        $this->reserved -= $this->reservations[$event->orderId->value];
        unset($this->reservations[$event->orderId->value]);
        $this->updatedAt = new DateTimeImmutable();
    }

    public function available(): int
    {
        return $this->onHand - $this->reserved;
    }
}
