<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Dbal;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Monadial\Nexus\Persistence\Event\EventEnvelope;
use Monadial\Nexus\Persistence\Event\EventStore;
use Monadial\Nexus\Persistence\Exception\ConcurrentModificationException;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Serialization\MessageSerializer;
use Monadial\Nexus\Serialization\PhpNativeSerializer;

final class DbalEventStore implements EventStore
{
    public function __construct(
        private readonly Connection $connection,
        private readonly MessageSerializer $serializer = new PhpNativeSerializer(),
    ) {
    }

    public function persist(PersistenceId $id, EventEnvelope ...$events): void
    {
        try {
            $this->connection->transactional(function () use ($id, $events): void {
                foreach ($events as $envelope) {
                    $this->connection->insert('nexus_event_journal', [
                        'persistence_id' => $id->toString(),
                        'sequence_nr' => $envelope->sequenceNr,
                        'event_type' => $envelope->eventType,
                        'event_data' => $this->serializer->serialize($envelope->event),
                        'metadata' => !empty($envelope->metadata) ? json_encode($envelope->metadata) : null,
                        'timestamp' => $envelope->timestamp->format('Y-m-d H:i:s'),
                    ]);
                }
            });
        } catch (UniqueConstraintViolationException $e) {
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
        $qb = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('nexus_event_journal')
            ->where('persistence_id = :pid')
            ->andWhere('sequence_nr >= :from')
            ->andWhere('sequence_nr <= :to')
            ->orderBy('sequence_nr', 'ASC')
            ->setParameter('pid', $id->toString())
            ->setParameter('from', $fromSequenceNr)
            ->setParameter('to', $toSequenceNr);

        $result = $qb->executeQuery();

        while ($row = $result->fetchAssociative()) {
            yield new EventEnvelope(
                persistenceId: $id,
                sequenceNr: (int) $row['sequence_nr'],
                event: $this->serializer->deserialize($row['event_data'], $row['event_type']),
                eventType: $row['event_type'],
                timestamp: new DateTimeImmutable($row['timestamp']),
                metadata: $row['metadata'] !== null ? json_decode($row['metadata'], true) : [],
            );
        }
    }

    public function deleteUpTo(PersistenceId $id, int $toSequenceNr): void
    {
        $this->connection->createQueryBuilder()
            ->delete('nexus_event_journal')
            ->where('persistence_id = :pid')
            ->andWhere('sequence_nr <= :to')
            ->setParameter('pid', $id->toString())
            ->setParameter('to', $toSequenceNr)
            ->executeStatement();
    }

    public function highestSequenceNr(PersistenceId $id): int
    {
        $result = $this->connection->createQueryBuilder()
            ->select('MAX(sequence_nr) as max_seq')
            ->from('nexus_event_journal')
            ->where('persistence_id = :pid')
            ->setParameter('pid', $id->toString())
            ->executeQuery()
            ->fetchAssociative();

        return (int) ($result['max_seq'] ?? 0);
    }
}
