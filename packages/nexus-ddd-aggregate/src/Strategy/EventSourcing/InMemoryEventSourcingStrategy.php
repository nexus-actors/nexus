<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Strategy\EventSourcing;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Aggregate\Event\InMemoryVersionedEventStore;
use Monadial\Nexus\Ddd\Aggregate\Event\Stream\SingleStreamStrategy;
use Monadial\Nexus\Ddd\Aggregate\Strategy\EventSourcedPersister;
use Monadial\Nexus\Ddd\Aggregate\Versioning\UpcasterPipeline;
use Monadial\Nexus\Ddd\Core\Aggregate\EventSourcedAggregateRoot;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use Monadial\Nexus\Persistence\Snapshot\InMemorySnapshotStore;
use Override;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;

/**
 * @psalm-api
 *
 * Convenience persister for tests and single-process Fiber demos: pre-wires
 * `InMemoryVersionedEventStore` + `InMemorySnapshotStore` + the supplied
 * `UpcasterPipeline` + `SingleStreamStrategy` + `NeverSnapshot` + `NullLogger`
 * around a fresh `EventSourcingStrategy`.
 *
 * Production deployments compose `EventSourcingStrategy` directly with
 * persistent backing stores.
 */
final readonly class InMemoryEventSourcingStrategy implements EventSourcedPersister
{
    private EventSourcingStrategy $strategy;

    public function __construct(UpcasterPipeline $upcasters, ClockInterface $clock)
    {
        $this->strategy = new EventSourcingStrategy(
            new InMemoryVersionedEventStore(),
            new InMemorySnapshotStore(),
            $upcasters,
            new SingleStreamStrategy(),
            new NeverSnapshot(),
            $clock,
            new NullLogger(),
        );
    }

    #[Override]
    public function load(string $entityClass, Identifier $id): Option
    {
        return $this->strategy->load($entityClass, $id);
    }

    #[Override]
    public function persist(EventSourcedAggregateRoot $entity): void
    {
        $this->strategy->persist($entity);
    }
}
