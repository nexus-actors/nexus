<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Platform\Persistence;

use Doctrine\ORM\EntityManagerInterface;
use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerPool;
use Monadial\Nexus\Persistence\Doctrine\DoctrineEventStore;
use Monadial\Nexus\Persistence\Event\EventEnvelope;
use Monadial\Nexus\Persistence\Event\EventStore;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Serialization\Exception\MessageSerializationException;
use Monadial\Nexus\Serialization\MessageSerializer;
use Monadial\Nexus\Serialization\TypeRegistry;
use Override;
use WeakMap;

/**
 * A durable, pool-friendly {@see EventStore}.
 *
 * Each operation borrows an EntityManager from the shared pool for its
 * duration instead of pinning one connection per actor for its whole
 * lifetime. The {@see DoctrineEventStore} wrapper is cached per pooled
 * EntityManager in a WeakMap — one instance per EM, reused across
 * operations, released automatically when the pool recycles the EM.
 *
 * Event envelopes from {@see PersistenceEngine} carry FQCN event types.
 * This store translates them to registry wire names (e.g.
 * `orders.order_placed.v1`) before writing to the journal so the
 * `event_type` column always holds the versioned wire name — the
 * upcasting seam. Load paths need no translation: the stored wire name
 * flows directly into the serializer's `deserialize()`.
 */
final class PooledDoctrineEventStore implements EventStore
{
    /** @var WeakMap<EntityManagerInterface, DoctrineEventStore> */
    private WeakMap $stores;

    public function __construct(
        private readonly EntityManagerPool $pool,
        private readonly MessageSerializer $serializer,
        private readonly TypeRegistry $registry,
    ) {
        /** @var WeakMap<EntityManagerInterface, DoctrineEventStore> $map */
        $map = new WeakMap();
        $this->stores = $map;
    }

    #[Override]
    public function persist(PersistenceId $id, EventEnvelope ...$events): void
    {
        $translated = [];

        foreach ($events as $envelope) {
            $className = $envelope->event::class;
            $wireName = $this->registry->nameForClass($className)
                ?? throw new MessageSerializationException(
                    $className,
                    "No type name registered for class '{$className}'",
                );

            $translated[] = new EventEnvelope(
                persistenceId: $envelope->persistenceId,
                sequenceNr: $envelope->sequenceNr,
                event: $envelope->event,
                eventType: $wireName,
                timestamp: $envelope->timestamp,
                writerId: $envelope->writerId,
                metadata: $envelope->metadata,
            );
        }

        $storeFor = $this->storeFor(...);

        $this->pool->withEntityManager(static function (EntityManagerInterface $em) use ($id, $translated, $storeFor): void {
            $storeFor($em)->persist($id, ...$translated);
        });
    }

    /**
     * @return list<EventEnvelope>
     */
    #[Override]
    public function load(PersistenceId $id, int $fromSequenceNr = 0, int $toSequenceNr = PHP_INT_MAX): iterable
    {
        $storeFor = $this->storeFor(...);

        return $this->pool->withEntityManager(
            static function (EntityManagerInterface $em) use ($storeFor, $id, $fromSequenceNr, $toSequenceNr): array {
                $events = [];

                foreach ($storeFor($em)->load($id, $fromSequenceNr, $toSequenceNr) as $event) {
                    $events[] = $event;
                }

                return $events;
            },
        );
    }

    #[Override]
    public function deleteUpTo(PersistenceId $id, int $toSequenceNr): void
    {
        $storeFor = $this->storeFor(...);

        $this->pool->withEntityManager(static function (EntityManagerInterface $em) use ($id, $toSequenceNr, $storeFor): void {
            $storeFor($em)->deleteUpTo($id, $toSequenceNr);
        });
    }

    #[Override]
    public function highestSequenceNr(PersistenceId $id): int
    {
        $storeFor = $this->storeFor(...);

        return $this->pool->withEntityManager(
            static fn(EntityManagerInterface $em): int => $storeFor($em)->highestSequenceNr($id),
        );
    }

    private function storeFor(EntityManagerInterface $em): DoctrineEventStore
    {
        $store = $this->stores[$em] ?? null;

        if ($store === null) {
            $store = new DoctrineEventStore($em, $this->serializer);
            $this->stores[$em] = $store;
        }

        return $store;
    }
}
