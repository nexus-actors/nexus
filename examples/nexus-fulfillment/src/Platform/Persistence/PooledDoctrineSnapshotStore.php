<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Platform\Persistence;

use Doctrine\ORM\EntityManagerInterface;
use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerPool;
use Monadial\Nexus\Persistence\Doctrine\DoctrineSnapshotStore;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\Snapshot\SnapshotEnvelope;
use Monadial\Nexus\Persistence\Snapshot\SnapshotStore;
use Monadial\Nexus\Serialization\MessageSerializer;
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
 */
final class PooledDoctrineSnapshotStore implements SnapshotStore
{
    /** @var WeakMap<EntityManagerInterface, DoctrineSnapshotStore> */
    private WeakMap $stores;

    public function __construct(
        private readonly EntityManagerPool $pool,
        private readonly MessageSerializer $serializer,
    ) {
        /** @var WeakMap<EntityManagerInterface, DoctrineSnapshotStore> $map */
        $map = new WeakMap();
        $this->stores = $map;
    }

    #[Override]
    public function save(PersistenceId $id, SnapshotEnvelope $snapshot): void
    {
        $storeFor = $this->storeFor(...);

        $this->pool->withEntityManager(static function (EntityManagerInterface $em) use ($id, $snapshot, $storeFor): void {
            $storeFor($em)->save($id, $snapshot);
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
