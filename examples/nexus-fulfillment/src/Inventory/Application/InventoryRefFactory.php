<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Inventory\Application;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Bus\Publish;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Sku;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;
use Monadial\Nexus\Persistence\Event\EventStore;
use Monadial\Nexus\Persistence\Snapshot\SnapshotStore;
use Monadial\Nexus\Runtime\Duration;

/**
 * Spawn-on-demand entity access: a dead or never-spawned inventory item actor
 * is (re)spawned and the persistence engine replays its events. Callers never
 * know whether the entity was already in memory.
 *
 * ActorSystem::spawn() prunes same-named dead children automatically, so
 * the isAlive() check here is belt-and-suspenders that avoids spawning
 * when a live actor is already cached.
 */
final class InventoryRefFactory
{
    /** @var array<string, ActorRef<object>> */
    private array $cache = [];

    /**
     * @param ActorRef<Publish> $bus
     */
    public function __construct(
        private readonly ActorSystem $system,
        private readonly EventStore $store,
        private readonly SnapshotStore $snapshots,
        private readonly ActorRef $bus,
        private readonly Duration $passivateAfter,
    ) {}

    /**
     * @return ActorRef<object>
     */
    public function of(TenantId $tenantId, Sku $sku): ActorRef
    {
        $name = "item-{$tenantId->value}.{$sku->value}";

        if (isset($this->cache[$name]) && $this->cache[$name]->isAlive()) {
            return $this->cache[$name];
        }

        unset($this->cache[$name]);

        /** @var ActorRef<object> $ref */
        $ref = $this->system->spawn(
            Props::fromBehavior(InventoryItemActor::behavior(
                $tenantId,
                $sku,
                $this->store,
                $this->snapshots,
                $this->bus,
                $this->passivateAfter,
            )),
            $name,
        );

        return $this->cache[$name] = $ref;
    }
}
