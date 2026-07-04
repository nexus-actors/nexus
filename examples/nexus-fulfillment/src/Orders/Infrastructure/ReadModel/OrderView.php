<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Orders\Infrastructure\ReadModel;

use DateTimeImmutable;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\Table;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\OrderCancelled;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\OrderPlaced;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\OrderStockReserved;

use function count;

/**
 * CQRS read side: a flat, indexed row per order. Derived state — the
 * event journal is the source of truth; this table is rebuildable.
 */
#[Entity]
#[Table(name: 'orders_view')]
#[Index(columns: ['tenant_id', 'status'])]
final class OrderView
{
    #[Column]
    public private(set) string $status;

    #[Column(name: 'total_amount')]
    public private(set) int $totalAmount = 0;

    #[Column(length: 3)]
    public private(set) string $currency = 'EUR';

    #[Column(name: 'line_count')]
    public private(set) int $lineCount = 0;

    #[Column(name: 'cancel_reason', nullable: true)]
    public private(set) ?string $cancelReason = null;

    #[Column(name: 'updated_at', type: 'datetime_immutable')]
    public private(set) DateTimeImmutable $updatedAt;

    public function __construct(
        #[Id]
        #[Column]
        public private(set) string $id,
        #[Id]
        #[Column(name: 'tenant_id')]
        public private(set) string $tenantId,
    ) {
        $this->status = 'placed';
        $this->updatedAt = new DateTimeImmutable();
    }

    public function applyPlaced(OrderPlaced $event): void
    {
        $this->status = 'placed';
        $this->totalAmount = $event->total->amount;
        $this->currency = $event->total->currency;
        $this->lineCount = count($event->lines);
        $this->updatedAt = new DateTimeImmutable();
    }

    public function applyCancelled(OrderCancelled $event): void
    {
        $this->status = 'cancelled';
        $this->cancelReason = $event->reason;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function applyStockReserved(OrderStockReserved $event): void
    {
        $this->status = 'stock_reserved';
        $this->updatedAt = new DateTimeImmutable();
    }
}
