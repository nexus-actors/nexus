<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Persistence;

use Doctrine\ORM\EntityManagerInterface;
use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerPool;
use Monadial\Nexus\Persistence\Doctrine\DoctrineEventStore;
use Monadial\Nexus\Persistence\Event\EventEnvelope;
use Monadial\Nexus\Persistence\Event\EventStore;
use Monadial\Nexus\Persistence\PersistenceId;

/**
 * A durable, pool-friendly {@see EventStore}.
 *
 * Each operation borrows an EntityManager from the shared pool for its
 * duration and wraps a {@see DoctrineEventStore} around it, instead of
 * pinning one connection per game actor for the actor's whole lifetime.
 * That keeps the "no connection held while a game sits idle" property the
 * event-sourced actor relies on (it has no passivation), and it is
 * coroutine-safe — the pool hands each borrow its own EM. Events land in the
 * `nexus_event_journal` table, so a worker restart replays a real log.
 */
final readonly class PooledDoctrineEventStore implements EventStore
{
    public function __construct(private EntityManagerPool $pool) {}

    public function persist(PersistenceId $id, EventEnvelope ...$events): void
    {
        $this->pool->withEntityManager(static function (EntityManagerInterface $em) use ($id, $events): void {
            new DoctrineEventStore($em)->persist($id, ...$events);
        });
    }

    /**
     * @return list<EventEnvelope>
     */
    public function load(PersistenceId $id, int $fromSequenceNr = 0, int $toSequenceNr = PHP_INT_MAX): iterable
    {
        return $this->pool->withEntityManager(
            static fn(EntityManagerInterface $em): array => [
                ...new DoctrineEventStore($em)->load($id, $fromSequenceNr, $toSequenceNr),
            ],
        );
    }

    public function deleteUpTo(PersistenceId $id, int $toSequenceNr): void
    {
        $this->pool->withEntityManager(static function (EntityManagerInterface $em) use ($id, $toSequenceNr): void {
            new DoctrineEventStore($em)->deleteUpTo($id, $toSequenceNr);
        });
    }

    public function highestSequenceNr(PersistenceId $id): int
    {
        return $this->pool->withEntityManager(
            static fn(EntityManagerInterface $em): int => new DoctrineEventStore($em)->highestSequenceNr($id),
        );
    }
}
