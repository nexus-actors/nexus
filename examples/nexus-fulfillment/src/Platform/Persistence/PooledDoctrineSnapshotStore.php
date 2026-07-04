<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Platform\Persistence;

use Doctrine\ORM\EntityManagerInterface;
use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerPool;
use Monadial\Nexus\Persistence\Doctrine\DoctrineSnapshotStore;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\Snapshot\SnapshotEnvelope;
use Monadial\Nexus\Persistence\Snapshot\SnapshotStore;
use Monadial\Nexus\Serialization\Exception\MessageSerializationException;
use Monadial\Nexus\Serialization\MessageSerializer;
use Monadial\Nexus\Serialization\TypeRegistry;
use Override;
use WeakMap;

/**
 * A durable, pool-friendly {@see SnapshotStore}.
 *
 * Each operation borrows an EntityManager from the shared pool for its
 * duration instead of pinning one connection per actor for its whole
 * lifetime. The {@see DoctrineSnapshotStore} wrapper is cached per pooled
 * EntityManager in a WeakMap — one instance per EM, reused across
 * operations, released automatically when the pool recycles the EM.
 *
 * Snapshot envelopes from {@see PersistenceEngine} carry FQCN state types.
 * This store translates them to registry wire names (e.g.
 * `orders.order_state.v1`) before writing so the `state_type` column always
 * holds the versioned wire name. Load paths need no translation: the stored
 * wire name flows directly into the serializer's `deserialize()`.
 */
final class PooledDoctrineSnapshotStore implements SnapshotStore
{
    /** @var WeakMap<EntityManagerInterface, DoctrineSnapshotStore> */
    private WeakMap $stores;

    public function __construct(
        private readonly EntityManagerPool $pool,
        private readonly MessageSerializer $serializer,
        private readonly TypeRegistry $registry,
    ) {
        /** @var WeakMap<EntityManagerInterface, DoctrineSnapshotStore> $map */
        $map = new WeakMap();
        $this->stores = $map;
    }

    #[Override]
    public function save(PersistenceId $id, SnapshotEnvelope $snapshot): void
    {
        $className = $snapshot->state::class;
        $wireName = $this->registry->nameForClass($className)
            ?? throw new MessageSerializationException(
                $className,
                "No type name registered for class '{$className}'",
            );

        $translated = new SnapshotEnvelope(
            persistenceId: $snapshot->persistenceId,
            sequenceNr: $snapshot->sequenceNr,
            state: $snapshot->state,
            stateType: $wireName,
            timestamp: $snapshot->timestamp,
            writerId: $snapshot->writerId,
        );

        $storeFor = $this->storeFor(...);

        $this->pool->withEntityManager(static function (EntityManagerInterface $em) use ($id, $translated, $storeFor): void {
            $storeFor($em)->save($id, $translated);
        });
    }

    #[Override]
    public function load(PersistenceId $id): ?SnapshotEnvelope
    {
        $storeFor = $this->storeFor(...);

        return $this->pool->withEntityManager(
            static fn(EntityManagerInterface $em): ?SnapshotEnvelope => $storeFor($em)->load($id),
        );
    }

    #[Override]
    public function delete(PersistenceId $id, int $maxSequenceNr): void
    {
        $storeFor = $this->storeFor(...);

        $this->pool->withEntityManager(static function (EntityManagerInterface $em) use ($id, $maxSequenceNr, $storeFor): void {
            $storeFor($em)->delete($id, $maxSequenceNr);
        });
    }

    private function storeFor(EntityManagerInterface $em): DoctrineSnapshotStore
    {
        $store = $this->stores[$em] ?? null;

        if ($store === null) {
            $store = new DoctrineSnapshotStore($em, $this->serializer);
            $this->stores[$em] = $store;
        }

        return $store;
    }
}
