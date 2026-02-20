<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Dbal;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\Snapshot\SnapshotEnvelope;
use Monadial\Nexus\Persistence\Snapshot\SnapshotStore;
use Monadial\Nexus\Serialization\MessageSerializer;
use Monadial\Nexus\Serialization\PhpNativeSerializer;
use Override;

/** @psalm-api */
final class DbalSnapshotStore implements SnapshotStore
{
    public function __construct(
        private readonly Connection $connection,
        private readonly MessageSerializer $serializer = new PhpNativeSerializer(),
    ) {}

    #[Override]
    public function save(PersistenceId $id, SnapshotEnvelope $snapshot): void
    {
        $this->connection->insert('nexus_snapshot_store', [
            'persistence_id' => $id->toString(),
            'sequence_nr' => $snapshot->sequenceNr,
            'state_data' => $this->serializer->serialize($snapshot->state),
            'state_type' => $snapshot->stateType,
            'timestamp' => $snapshot->timestamp->format('Y-m-d H:i:s'),
        ]);
    }

    #[Override]
    public function load(PersistenceId $id): ?SnapshotEnvelope
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('nexus_snapshot_store')
            ->where('persistence_id = :pid')
            ->orderBy('sequence_nr', 'DESC')
            ->setMaxResults(1)
            ->setParameter('pid', $id->toString())
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return new SnapshotEnvelope(
            persistenceId: $id,
            sequenceNr: (int) $row['sequence_nr'],
            state: $this->serializer->deserialize((string) $row['state_data'], (string) $row['state_type']),
            stateType: (string) $row['state_type'],
            timestamp: new DateTimeImmutable((string) $row['timestamp']),
        );
    }

    #[Override]
    public function delete(PersistenceId $id, int $maxSequenceNr): void
    {
        $this->connection->createQueryBuilder()
            ->delete('nexus_snapshot_store')
            ->where('persistence_id = :pid')
            ->andWhere('sequence_nr <= :max')
            ->setParameter('pid', $id->toString())
            ->setParameter('max', $maxSequenceNr)
            ->executeStatement();
    }
}
