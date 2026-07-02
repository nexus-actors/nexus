<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Dbal;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Monadial\Nexus\Persistence\Exception\ConcurrentModificationException;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\State\DurableStateEnvelope;
use Monadial\Nexus\Persistence\State\DurableStateStore;
use Monadial\Nexus\Serialization\MessageSerializer;
use Monadial\Nexus\Serialization\PhpNativeSerializer;
use Override;
use Symfony\Component\Uid\Ulid;

/** @psalm-api */
final readonly class DbalDurableStateStore implements DurableStateStore
{
    public function __construct(
        private Connection $connection,
        private MessageSerializer $serializer = new PhpNativeSerializer(),
    ) {}

    #[Override]
    public function get(PersistenceId $id): ?DurableStateEnvelope
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('nexus_durable_state')
            ->where('persistence_id = :pid')
            ->setParameter('pid', $id->toString())
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return new DurableStateEnvelope(
            persistenceId: $id,
            version: (int) $row['version'],
            state: $this->serializer->deserialize((string) $row['state_data'], (string) $row['state_type']),
            stateType: (string) $row['state_type'],
            timestamp: new DateTimeImmutable((string) $row['timestamp']),
            writerId: Ulid::fromString((string) $row['writer_id']),
        );
    }

    #[Override]
    public function upsert(PersistenceId $id, DurableStateEnvelope $state): void
    {
        $expectedVersion = $state->version - 1;

        $affected = $this->connection->createQueryBuilder()
            ->update('nexus_durable_state')
            ->set('version', ':version')
            ->set('state_type', ':state_type')
            ->set('state_data', ':state_data')
            ->set('timestamp', ':timestamp')
            ->set('writer_id', ':writer_id')
            ->where('persistence_id = :pid')
            ->andWhere('version = :expected_version')
            ->setParameter('pid', $id->toString())
            ->setParameter('expected_version', $expectedVersion)
            ->setParameter('version', $state->version)
            ->setParameter('state_type', $state->stateType)
            ->setParameter('state_data', $this->serializer->serialize($state->state))
            ->setParameter('timestamp', $state->timestamp->format('Y-m-d H:i:s'))
            ->setParameter('writer_id', (string) $state->writerId)
            ->executeStatement();

        if ($affected === 0) {
            // Check if the row exists — if it does, it was a version conflict
            $exists = $this->connection->createQueryBuilder()
                ->select('1')
                ->from('nexus_durable_state')
                ->where('persistence_id = :pid')
                ->setParameter('pid', $id->toString())
                ->executeQuery()
                ->fetchOne();

            if ($exists !== false) {
                throw new ConcurrentModificationException(
                    $id,
                    $expectedVersion,
                    "Optimistic lock failed for persistence ID '{$id->toString()}': expected version {$expectedVersion}",
                );
            }

            $this->connection->insert('nexus_durable_state', [
                'persistence_id' => $id->toString(),
                'state_data' => $this->serializer->serialize($state->state),
                'state_type' => $state->stateType,
                'timestamp' => $state->timestamp->format('Y-m-d H:i:s'),
                'version' => $state->version,
                'writer_id' => (string) $state->writerId,
            ]);
        }
    }

    #[Override]
    public function delete(PersistenceId $id): void
    {
        $this->connection->createQueryBuilder()
            ->delete('nexus_durable_state')
            ->where('persistence_id = :pid')
            ->setParameter('pid', $id->toString())
            ->executeStatement();
    }
}
