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
    #[Id]
    #[Column]
    private string $id;

    #[Column(name: 'tenant_id')]
    private string $tenantId;

    #[Column]
    private string $status;

    #[Column(name: 'total_amount')]
    private int $totalAmount = 0;

    #[Column(length: 3)]
    private string $currency = 'EUR';

    #[Column(name: 'line_count')]
    private int $lineCount = 0;

    #[Column(name: 'cancel_reason', nullable: true)]
    private ?string $cancelReason = null;

    #[Column(name: 'updated_at')]
    private DateTimeImmutable $updatedAt;

    public function __construct(string $id, string $tenantId)
    {
        $this->id = $id;
        $this->tenantId = $tenantId;
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

    public function id(): string
    {
        return $this->id;
    }

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function totalAmount(): int
    {
        return $this->totalAmount;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function lineCount(): int
    {
        return $this->lineCount;
    }

    public function cancelReason(): ?string
    {
        return $this->cancelReason;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
