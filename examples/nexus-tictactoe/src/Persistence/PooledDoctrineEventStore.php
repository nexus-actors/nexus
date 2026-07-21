<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Persistence;

use Doctrine\ORM\EntityManagerInterface;
use Monadial\Nexus\Serialization\PhpNativeSerializer;
use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerPool;
use Monadial\Nexus\Persistence\Doctrine\DoctrineEventStore;
use Monadial\Nexus\Persistence\Event\EventEnvelope;
use Monadial\Nexus\Persistence\Event\EventStore;
use Monadial\Nexus\Persistence\PersistenceId;
use Override;

use function iterator_to_array;

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

    #[Override]
    public function persist(PersistenceId $id, EventEnvelope ...$events): void
    {
        $this->pool->withEntityManager(static function (EntityManagerInterface $em) use ($id, $events): void {
            new DoctrineEventStore($em, PhpNativeSerializer::forTrustedData())->persist($id, ...$events);
        });
    }

    /**
     * @return list<EventEnvelope>
     */
    #[Override]
    public function load(PersistenceId $id, int $fromSequenceNr = 0, int $toSequenceNr = PHP_INT_MAX): iterable
    {
        return $this->pool->withEntityManager(
            static fn(EntityManagerInterface $em): array => iterator_to_array(
                new DoctrineEventStore($em, PhpNativeSerializer::forTrustedData())->load($id, $fromSequenceNr, $toSequenceNr),
                preserve_keys: false,
            ),
        );
    }

    #[Override]
    public function deleteUpTo(PersistenceId $id, int $toSequenceNr): void
    {
        $this->pool->withEntityManager(static function (EntityManagerInterface $em) use ($id, $toSequenceNr): void {
            new DoctrineEventStore($em, PhpNativeSerializer::forTrustedData())->deleteUpTo($id, $toSequenceNr);
        });
    }

    #[Override]
    public function highestSequenceNr(PersistenceId $id): int
    {
        return $this->pool->withEntityManager(
            static fn(EntityManagerInterface $em): int => new DoctrineEventStore($em, PhpNativeSerializer::forTrustedData())->highestSequenceNr($id),
        );
    }
}
