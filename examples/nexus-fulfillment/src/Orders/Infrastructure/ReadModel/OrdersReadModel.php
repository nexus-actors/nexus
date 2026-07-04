<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Orders\Infrastructure\ReadModel;

use Doctrine\ORM\EntityManagerInterface;
use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerPool;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\OrderCancelled;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\OrderPlaced;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\OrderStockReserved;

/**
 * Write side of the read model: one pooled-EM upsert per event.
 */
final readonly class OrdersReadModel
{
    public function __construct(private EntityManagerPool $pool) {}

    public function apply(object $event): void
    {
        if ($event instanceof OrderPlaced) {
            $this->pool->withEntityManager(static function (EntityManagerInterface $em) use ($event): void {
                $row = $em->find(OrderView::class, ['id' => $event->orderId->value, 'tenantId' => $event->tenantId->value])
                    ?? new OrderView($event->orderId->value, $event->tenantId->value);
                $row->applyPlaced($event);
                $em->persist($row);
                $em->flush();
            });

            return;
        }

        if ($event instanceof OrderCancelled) {
            $this->pool->withEntityManager(static function (EntityManagerInterface $em) use ($event): void {
                $row = $em->find(OrderView::class, ['id' => $event->orderId->value, 'tenantId' => $event->tenantId->value]);

                if ($row === null) {
                    return;
                }

                $row->applyCancelled($event);
                $em->persist($row);
                $em->flush();
            });

            return;
        }

        if ($event instanceof OrderStockReserved) {
            $this->pool->withEntityManager(static function (EntityManagerInterface $em) use ($event): void {
                $row = $em->find(OrderView::class, ['id' => $event->orderId->value, 'tenantId' => $event->tenantId->value]);

                if ($row === null) {
                    return;
                }

                $row->applyStockReserved($event);
                $em->persist($row);
                $em->flush();
            });
        }
    }
}
