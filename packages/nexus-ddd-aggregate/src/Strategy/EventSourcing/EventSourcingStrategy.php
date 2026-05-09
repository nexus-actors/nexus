<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Strategy\EventSourcing;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Aggregate\Event\AggregateStreamId;
use Monadial\Nexus\Ddd\Aggregate\Event\StoredEvent;
use Monadial\Nexus\Ddd\Aggregate\Event\Stream\StreamStrategy;
use Monadial\Nexus\Ddd\Aggregate\Event\VersionedEventStore;
use Monadial\Nexus\Ddd\Aggregate\Exception\AggregateAlreadyExistsException;
use Monadial\Nexus\Ddd\Aggregate\Snapshot\SnapshotStore;
use Monadial\Nexus\Ddd\Aggregate\Strategy\EventSourcedPersister;
use Monadial\Nexus\Ddd\Aggregate\Versioning\UpcasterPipeline;
use Monadial\Nexus\Ddd\Core\Aggregate\AggregateRoot;
use Monadial\Nexus\Ddd\Core\Aggregate\EventSourcedAggregateRoot;
use Monadial\Nexus\Ddd\Core\Aggregate\EventSourcedAggregateRootAccessor;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Core\Exception\OptimisticLockException;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use Override;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;

use function array_map;
use function count;
use function iterator_to_array;

/**
 * @psalm-api
 *
 * Production-shape event-sourced persister. Composes the five collaborators
 * the spec mandates (per v6 §9.2 + §10.3.1 + §25.6.4):
 *   - `VersionedEventStore` for OCC-protected appends and stream reads.
 *   - `SnapshotStore` for snapshot lookup during replay.
 *   - `UpcasterPipeline` for in-flight event transformation.
 *   - `StreamStrategy` for logical-stream resolution (currently consumed
 *     by physical adapters; the in-memory store ignores it).
 *   - `EventSourcedAggregateRootAccessor` (friend-class) for the protected
 *     replay/version/recorded-events hooks on the aggregate.
 *
 * **Snapshot WRITE path is deferred** (per round-4 plan revision): adding
 * a public `state()` accessor on `EventSourcedAggregateRoot` would touch
 * shipped P0 code (and break `NoGettersSettersOnAggregateRule`). The
 * follow-up PR adds the state-extraction hook to
 * `EventSourcedAggregateRootAccessor` and reinstates the snapshot save.
 *
 * **Snapshot READ — incompatible-state-type** path falls back to full
 * replay from event 1 (per v6 §10.3.1). A `ddd.snapshot.incompatible_fallback`
 * warning is logged.
 *
 * **Snapshot READ — compatible-state-type** path queries `(snapshot.seq + 1, MAX)`
 * from the event store and replays those onto a blank aggregate; the
 * snapshot's stored state is NOT yet re-applied (also deferred — needs the
 * same state-extraction hook). Version arithmetic is correct: snapshot
 * baseline plus replayed event count.
 *
 * @psalm-suppress PossiblyUnusedProperty `$upcasters`, `$streamStrategy`,
 *                  and `$snapshotStrategy` are wired now but consumed by
 *                  the deferred snapshot WRITE path / the upcasting hook
 *                  in the persistent backends. Keeping them on the
 *                  constructor stabilises the public API across PRs.
 */
final readonly class EventSourcingStrategy implements EventSourcedPersister
{
    /**
     * @psalm-suppress UnusedProperty see class-level docblock.
     */
    private UpcasterPipeline $upcasters;

    /**
     * @psalm-suppress UnusedProperty see class-level docblock.
     */
    private StreamStrategy $streamStrategy;

    /**
     * @psalm-suppress UnusedProperty see class-level docblock.
     */
    private SnapshotStrategy $snapshotStrategy;

    public function __construct(
        private VersionedEventStore $store,
        private SnapshotStore $snapshots,
        UpcasterPipeline $upcasters,
        StreamStrategy $streamStrategy,
        SnapshotStrategy $snapshotStrategy,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private EventSourcedAggregateRootAccessor $accessor = new EventSourcedAggregateRootAccessor(),
    ) {
        $this->upcasters = $upcasters;
        $this->streamStrategy = $streamStrategy;
        $this->snapshotStrategy = $snapshotStrategy;
    }

    /**
     * @template T of EventSourcedAggregateRoot
     *
     * @param class-string<T> $entityClass
     *
     * @return Option<T>
     *
     * @psalm-suppress InvalidReturnType,InvalidReturnStatement — `instantiateBlank`
     *                  carries `class-string<T>` to a `T` instance via reflection;
     *                  Psalm can't track that constraint through `newInstanceWithoutConstructor`,
     *                  but the runtime contract holds.
     */
    #[Override]
    public function load(string $entityClass, Identifier $id): Option
    {
        $streamId = AggregateStreamId::for($entityClass, $id);

        $snapshotOpt = $this->snapshots->load($streamId);
        $snapshotBaseline = 0;
        $startFromSeq = 1;

        if ($snapshotOpt->isSome()) {
            $snapshot = $snapshotOpt->get();

            if ($snapshot->stateType === $entityClass) {
                $snapshotBaseline = $snapshot->sequenceNr;
                $startFromSeq = $snapshot->sequenceNr + 1;
            } else {
                $this->logger->warning(
                    'snapshot incompatible for {entity} {id}; falling back to full replay',
                    [
                        'actualStateType' => $snapshot->stateType,
                        'entity' => $entityClass,
                        'expectedStateType' => $entityClass,
                        'id' => $id->value(),
                        'metric' => 'ddd.snapshot.incompatible_fallback',
                    ],
                );
            }
        }

        $storedEvents = iterator_to_array($this->store->load($streamId, $startFromSeq), false);

        if ($snapshotBaseline === 0 && $storedEvents === []) {
            return Option::none();
        }

        $aggregate = $this->instantiateBlank($entityClass, $id);

        $events = array_map(static fn(StoredEvent $e): DomainEvent => $e->event, $storedEvents);
        $this->accessor->replayOn($aggregate, $events);

        $totalVersion = $snapshotBaseline + count($events);
        $this->accessor->rehydrateVersionOn($aggregate, $totalVersion);

        return Option::some($aggregate);
    }

    /**
     * @throws OptimisticLockException
     * @throws AggregateAlreadyExistsException
     *
     * @psalm-suppress ArgumentTypeCoercion `$entity::class` is `class-string`
     *                  and `$entity->id()->value()` is documented as scalar
     *                  string; both are non-empty in practice but the type
     *                  system can't see that.
     */
    #[Override]
    public function persist(EventSourcedAggregateRoot $entity): void
    {
        $events = $this->accessor->popRecordedEventsFrom($entity);

        if ($events === []) {
            return;
        }

        $streamId = AggregateStreamId::for($entity::class, $entity->id());
        $aggregateVersion = $this->accessor->extractVersion($entity);
        $expectedVersion = $aggregateVersion - count($events);

        $storedEvents = [];
        $seq = $expectedVersion + 1;
        $now = $this->clock->now();

        foreach ($events as $event) {
            $storedEvents[] = new StoredEvent($streamId, $seq++, $event, $event::class, $now);
        }

        try {
            $this->store->appendIfVersion($streamId, $expectedVersion, ...$storedEvents);
        } catch (OptimisticLockException $e) {
            if ($expectedVersion === 0) {
                throw AggregateAlreadyExistsException::for($entity::class, $entity->id()->value());
            }

            throw $e;
        }
    }

    /**
     * Instantiate an aggregate without invoking its (private) constructor,
     * then write the readonly `id` field via reflection. The strategy then
     * drives state via `replayOn()`. This is the standard event-sourcing
     * "blank rehydration" pattern — equivalent to Akka Persistence's
     * `internalRecovery`.
     *
     * @template T of EventSourcedAggregateRoot
     *
     * @param class-string<T> $entityClass
     *
     * @return T
     *
     * @psalm-suppress UnsafeInstantiation,MixedInferredReturnType,MixedReturnStatement
     *                  `newInstanceWithoutConstructor` is the documented entry
     *                  point for snapshot/replay rehydration; the strategy IS
     *                  the framework piece that's allowed to bypass the
     *                  private ctor. Reflection's return type is `object`,
     *                  but `class-string<T>` constrains it to `T`.
     */
    private function instantiateBlank(string $entityClass, Identifier $id): EventSourcedAggregateRoot
    {
        $reflection = new ReflectionClass($entityClass);
        $aggregate = $reflection->newInstanceWithoutConstructor();

        $idProperty = (new ReflectionClass(AggregateRoot::class))->getProperty('id');
        $idProperty->setValue($aggregate, $id);

        return $aggregate;
    }
}
