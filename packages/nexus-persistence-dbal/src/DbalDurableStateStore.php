<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Dbal;

use Doctrine\DBAL\Connection;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\State\DurableStateEnvelope;
use Monadial\Nexus\Persistence\State\DurableStateStore;

final class DbalDurableStateStore implements DurableStateStore
{
    public function __construct(
        private readonly Connection $connection,
    ) {}

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
            revision: (int) $row['revision'],
            state: unserialize($row['state_data']),
            stateType: $row['state_type'],
            timestamp: new \DateTimeImmutable($row['timestamp']),
        );
    }

    public function upsert(PersistenceId $id, DurableStateEnvelope $state): void
    {
        $affected = $this->connection->createQueryBuilder()
            ->update('nexus_durable_state')
            ->set('revision', ':revision')
            ->set('state_type', ':state_type')
            ->set('state_data', ':state_data')
            ->set('timestamp', ':timestamp')
            ->where('persistence_id = :pid')
            ->setParameter('pid', $id->toString())
            ->setParameter('revision', $state->revision)
            ->setParameter('state_type', $state->stateType)
            ->setParameter('state_data', serialize($state->state))
            ->setParameter('timestamp', $state->timestamp->format('Y-m-d H:i:s'))
            ->executeStatement();

        if ($affected === 0) {
            $this->connection->insert('nexus_durable_state', [
                'persistence_id' => $id->toString(),
                'revision' => $state->revision,
                'state_type' => $state->stateType,
                'state_data' => serialize($state->state),
                'timestamp' => $state->timestamp->format('Y-m-d H:i:s'),
            ]);
        }
    }

    public function delete(PersistenceId $id): void
    {
        $this->connection->createQueryBuilder()
            ->delete('nexus_durable_state')
            ->where('persistence_id = :pid')
            ->setParameter('pid', $id->toString())
            ->executeStatement();
    }
}
