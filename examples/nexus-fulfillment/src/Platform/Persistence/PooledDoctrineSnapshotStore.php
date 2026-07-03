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

/**
 * A durable, pool-friendly {@see SnapshotStore}.
 *
 * Each operation borrows an EntityManager from the shared pool for its
 * duration, wrapping a {@see DoctrineSnapshotStore} with the given serializer,
 * instead of pinning one connection per actor for its whole lifetime.
 */
final readonly class PooledDoctrineSnapshotStore implements SnapshotStore
{
    public function __construct(
        private EntityManagerPool $pool,
        private MessageSerializer $serializer,
    ) {}

    #[Override]
    public function save(PersistenceId $id, SnapshotEnvelope $snapshot): void
    {
        $serializer = $this->serializer;

        $this->pool->withEntityManager(static function (EntityManagerInterface $em) use ($id, $snapshot, $serializer): void {
            new DoctrineSnapshotStore($em, $serializer)->save($id, $snapshot);
        });
    }

    #[Override]
    public function load(PersistenceId $id): ?SnapshotEnvelope
    {
        $serializer = $this->serializer;

        return $this->pool->withEntityManager(
            static fn(EntityManagerInterface $em): ?SnapshotEnvelope => new DoctrineSnapshotStore($em, $serializer)->load($id),
        );
    }

    #[Override]
    public function delete(PersistenceId $id, int $maxSequenceNr): void
    {
        $serializer = $this->serializer;

        $this->pool->withEntityManager(static function (EntityManagerInterface $em) use ($id, $maxSequenceNr, $serializer): void {
            new DoctrineSnapshotStore($em, $serializer)->delete($id, $maxSequenceNr);
        });
    }
}
