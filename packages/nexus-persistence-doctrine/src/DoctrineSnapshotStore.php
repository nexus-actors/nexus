<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Monadial\Nexus\Persistence\Doctrine\Entity\SnapshotEntry;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\Snapshot\SnapshotEnvelope;
use Monadial\Nexus\Persistence\Snapshot\SnapshotStore;
use Monadial\Nexus\Serialization\MessageSerializer;
use Monadial\Nexus\Serialization\PhpNativeSerializer;

final class DoctrineSnapshotStore implements SnapshotStore
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageSerializer $serializer = new PhpNativeSerializer(),
    ) {
    }

    public function save(PersistenceId $id, SnapshotEnvelope $snapshot): void
    {
        $entry = new SnapshotEntry(
            persistenceId: $id->toString(),
            sequenceNr: $snapshot->sequenceNr,
            stateType: $snapshot->stateType,
            stateData: $this->serializer->serialize($snapshot->state),
            timestamp: $snapshot->timestamp,
        );

        $this->em->persist($entry);
        $this->em->flush();
    }

    public function load(PersistenceId $id): ?SnapshotEnvelope
    {
        $entry = $this->em->createQueryBuilder()
            ->select('s')
            ->from(SnapshotEntry::class, 's')
            ->where('s.persistenceId = :pid')
            ->orderBy('s.sequenceNr', 'DESC')
            ->setMaxResults(1)
            ->setParameter('pid', $id->toString())
            ->getQuery()
            ->getOneOrNullResult();

        if ($entry === null) {
            return null;
        }

        return new SnapshotEnvelope(
            persistenceId: $id,
            sequenceNr: (int) $entry->sequenceNr,
            state: $this->serializer->deserialize($entry->stateData, $entry->stateType),
            stateType: $entry->stateType,
            timestamp: $entry->timestamp,
        );
    }

    public function delete(PersistenceId $id, int $maxSequenceNr): void
    {
        $this->em->createQueryBuilder()
            ->delete(SnapshotEntry::class, 's')
            ->where('s.persistenceId = :pid')
            ->andWhere('s.sequenceNr <= :max')
            ->setParameter('pid', $id->toString())
            ->setParameter('max', $maxSequenceNr)
            ->getQuery()
            ->execute();
    }
}
