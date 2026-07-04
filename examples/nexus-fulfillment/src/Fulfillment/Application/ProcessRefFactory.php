<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Fulfillment\Application;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Example\Fulfillment\Inventory\Application\InventoryRefFactory;
use Monadial\Nexus\Example\Fulfillment\Orders\Application\OrderRefFactory;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;
use Monadial\Nexus\Persistence\Event\EventStore;
use Monadial\Nexus\Persistence\Snapshot\SnapshotStore;
use Monadial\Nexus\Runtime\Duration;

/**
 * Spawn-on-demand saga access: a dead or never-spawned process actor is
 * (re)spawned and the persistence engine replays its events. Callers
 * never know whether the saga was already in memory.
 *
 * Actor name uses dot separator: 'process-{tenant}.{orderId}'.
 * PersistenceId uses pipe: 'FulfillmentProcess|{tenant}|{orderId}'.
 */
final class ProcessRefFactory
{
    /** @var array<string, ActorRef<object>> */
    private array $cache = [];

    public function __construct(
        private readonly ActorSystem $system,
        private readonly EventStore $store,
        private readonly SnapshotStore $snapshots,
        private readonly OrderRefFactory $orders,
        private readonly InventoryRefFactory $inventory,
        private readonly Duration $passivateAfter,
    ) {}

    /**
     * @return ActorRef<object>
     */
    public function of(TenantId $tenantId, OrderId $orderId): ActorRef
    {
        $name = "process-{$tenantId->value}.{$orderId->value}";

        if (isset($this->cache[$name]) && $this->cache[$name]->isAlive()) {
            return $this->cache[$name];
        }

        unset($this->cache[$name]);

        /** @var ActorRef<object> $ref */
        $ref = $this->system->spawn(
            Props::fromBehavior(FulfillmentProcessActor::behavior(
                $tenantId,
                $orderId,
                $this->store,
                $this->snapshots,
                $this->orders,
                $this->inventory,
                $this->passivateAfter,
            )),
            $name,
        );

        return $this->cache[$name] = $ref;
    }
}
