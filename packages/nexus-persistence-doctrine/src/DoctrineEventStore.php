<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Doctrine;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\EntityIdentityCollisionException;
use Monadial\Nexus\Persistence\Doctrine\Entity\EventEntry;
use Monadial\Nexus\Persistence\Event\EventEnvelope;
use Monadial\Nexus\Persistence\Event\EventStore;
use Monadial\Nexus\Persistence\Exception\ConcurrentModificationException;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Serialization\MessageSerializer;
use Monadial\Nexus\Serialization\PhpNativeSerializer;

final class DoctrineEventStore implements EventStore
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageSerializer $serializer = new PhpNativeSerializer(),
    ) {}

    public function persist(PersistenceId $id, EventEnvelope ...$events): void
    {
        try {
            foreach ($events as $envelope) {
                $entry = new EventEntry(
                    persistenceId: $id->toString(),
                    sequenceNr: $envelope->sequenceNr,
                    eventType: $envelope->eventType,
                    eventData: $this->serializer->serialize($envelope->event),
                    timestamp: $envelope->timestamp,
                    metadata: $envelope->metadata !== [] ? json_encode($envelope->metadata) : null,
                );

                $this->em->persist($entry);
            }

            $this->em->flush();
        } catch (UniqueConstraintViolationException | EntityIdentityCollisionException $e) {
            $sequenceNr = $events[0]->sequenceNr ?? 0;

            throw new ConcurrentModificationException(
                $id,
                $sequenceNr,
                "Duplicate sequence number for persistence ID '{$id->toString()}'",
                $e,
            );
        }
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
                event: $this->serializer->deserialize($entry->eventData, $entry->eventType),
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
