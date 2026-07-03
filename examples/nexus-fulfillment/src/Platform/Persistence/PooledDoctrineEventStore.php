<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Platform\Persistence;

use Doctrine\ORM\EntityManagerInterface;
use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerPool;
use Monadial\Nexus\Persistence\Doctrine\DoctrineEventStore;
use Monadial\Nexus\Persistence\Event\EventEnvelope;
use Monadial\Nexus\Persistence\Event\EventStore;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Serialization\MessageSerializer;
use Override;

/**
 * A durable, pool-friendly {@see EventStore}.
 *
 * Each operation borrows an EntityManager from the shared pool for its
 * duration, wrapping a {@see DoctrineEventStore} with the given serializer,
 * instead of pinning one connection per actor for its whole lifetime.
 */
final readonly class PooledDoctrineEventStore implements EventStore
{
    public function __construct(
        private EntityManagerPool $pool,
        private MessageSerializer $serializer,
    ) {}

    #[Override]
    public function persist(PersistenceId $id, EventEnvelope ...$events): void
    {
        $serializer = $this->serializer;

        $this->pool->withEntityManager(static function (EntityManagerInterface $em) use ($id, $events, $serializer): void {
            new DoctrineEventStore($em, $serializer)->persist($id, ...$events);
        });
    }

    /**
     * @return list<EventEnvelope>
     */
    #[Override]
    public function load(PersistenceId $id, int $fromSequenceNr = 0, int $toSequenceNr = PHP_INT_MAX): iterable
    {
        $serializer = $this->serializer;

        return $this->pool->withEntityManager(
            static function (EntityManagerInterface $em) use ($serializer, $id, $fromSequenceNr, $toSequenceNr): array {
                $events = [];

                foreach (new DoctrineEventStore($em, $serializer)->load($id, $fromSequenceNr, $toSequenceNr) as $event) {
                    $events[] = $event;
                }

                return $events;
            },
        );
    }

    #[Override]
    public function deleteUpTo(PersistenceId $id, int $toSequenceNr): void
    {
        $serializer = $this->serializer;

        $this->pool->withEntityManager(static function (EntityManagerInterface $em) use ($id, $toSequenceNr, $serializer): void {
            new DoctrineEventStore($em, $serializer)->deleteUpTo($id, $toSequenceNr);
        });
    }

    #[Override]
    public function highestSequenceNr(PersistenceId $id): int
    {
        $serializer = $this->serializer;

        return $this->pool->withEntityManager(
            static fn(EntityManagerInterface $em): int => new DoctrineEventStore($em, $serializer)->highestSequenceNr($id),
        );
    }
}
