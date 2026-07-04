<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Inventory\Infrastructure\ReadModel;

use Doctrine\ORM\EntityManagerInterface;
use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerPool;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\Restocked;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\StockReleased;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\StockReserved;

/**
 * Write side of the read model: one pooled-EM upsert per event.
 *
 * Restocked creates the row if missing; reserve/release fold onto an
 * existing row (skipped when absent — the domain always restocks first).
 * StockReservationRejected and other events are no-op skips.
 */
final readonly class InventoryReadModel
{
    public function __construct(private EntityManagerPool $pool) {}

    public function apply(object $event): void
    {
        if ($event instanceof Restocked) {
            $this->pool->withEntityManager(static function (EntityManagerInterface $em) use ($event): void {
                $row = $em->find(InventoryLevel::class, ['sku' => $event->sku->value, 'tenantId' => $event->tenantId->value])
                    ?? new InventoryLevel($event->tenantId->value, $event->sku->value);
                $row->applyRestocked($event);
                $em->persist($row);
                $em->flush();
            });

            return;
        }

        if ($event instanceof StockReserved) {
            $this->pool->withEntityManager(static function (EntityManagerInterface $em) use ($event): void {
                $row = $em->find(InventoryLevel::class, ['sku' => $event->sku->value, 'tenantId' => $event->tenantId->value]);

                if ($row === null) {
                    return;
                }

                $row->applyReserved($event);
                $em->persist($row);
                $em->flush();
            });

            return;
        }

        if ($event instanceof StockReleased) {
            $this->pool->withEntityManager(static function (EntityManagerInterface $em) use ($event): void {
                $row = $em->find(InventoryLevel::class, ['sku' => $event->sku->value, 'tenantId' => $event->tenantId->value]);

                if ($row === null) {
                    return;
                }

                $row->applyReleased($event);
                $em->persist($row);
                $em->flush();
            });
        }
    }
}
