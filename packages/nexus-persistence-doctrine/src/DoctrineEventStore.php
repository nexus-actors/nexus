<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Monadial\Nexus\Persistence\Doctrine\Entity\EventEntry;
use Monadial\Nexus\Persistence\Event\EventEnvelope;
use Monadial\Nexus\Persistence\Event\EventStore;
use Monadial\Nexus\Persistence\PersistenceId;

final class DoctrineEventStore implements EventStore
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function persist(PersistenceId $id, EventEnvelope ...$events): void
    {
        foreach ($events as $envelope) {
            $entry = new EventEntry();
            $entry->persistenceId = $id->toString();
            $entry->sequenceNr = $envelope->sequenceNr;
            $entry->eventType = $envelope->eventType;
            $entry->eventData = serialize($envelope->event);
            $entry->metadata = !empty($envelope->metadata) ? json_encode($envelope->metadata) : null;
            $entry->timestamp = $envelope->timestamp;

            $this->em->persist($entry);
        }

        $this->em->flush();
    }

    /** @return iterable<EventEnvelope> */
    public function load(PersistenceId $id, int $fromSequenceNr = 0, int $toSequenceNr = PHP_INT_MAX): iterable
    {
        $qb = $this->em->createQueryBuilder()
            ->select('e')
            ->from(EventEntry::class, 'e')
            ->where('e.persistenceId = :pid')
            ->andWhere('e.sequenceNr >= :from')
            ->andWhere('e.sequenceNr <= :to')
            ->orderBy('e.sequenceNr', 'ASC')
            ->setParameter('pid', $id->toString())
            ->setParameter('from', $fromSequenceNr)
            ->setParameter('to', $toSequenceNr);

        foreach ($qb->getQuery()->getResult() as $entry) {
            yield new EventEnvelope(
                persistenceId: $id,
                sequenceNr: (int) $entry->sequenceNr,
                event: unserialize($entry->eventData),
                eventType: $entry->eventType,
                timestamp: $entry->timestamp,
                metadata: $entry->metadata !== null ? json_decode($entry->metadata, true) : [],
            );
        }
    }

    public function deleteUpTo(PersistenceId $id, int $toSequenceNr): void
    {
        $this->em->createQueryBuilder()
            ->delete(EventEntry::class, 'e')
            ->where('e.persistenceId = :pid')
            ->andWhere('e.sequenceNr <= :to')
            ->setParameter('pid', $id->toString())
            ->setParameter('to', $toSequenceNr)
            ->getQuery()
            ->execute();
    }

    public function highestSequenceNr(PersistenceId $id): int
    {
        $result = $this->em->createQueryBuilder()
            ->select('MAX(e.sequenceNr)')
            ->from(EventEntry::class, 'e')
            ->where('e.persistenceId = :pid')
            ->setParameter('pid', $id->toString())
            ->getQuery()
            ->getSingleScalarResult();

        return (int) ($result ?? 0);
    }
}
