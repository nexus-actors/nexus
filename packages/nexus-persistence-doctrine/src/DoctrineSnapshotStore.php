<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Monadial\Nexus\Persistence\Doctrine\Entity\SnapshotEntry;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\Snapshot\SnapshotEnvelope;
use Monadial\Nexus\Persistence\Snapshot\SnapshotStore;
use Monadial\Nexus\Serialization\MessageSerializer;
use Override;
use Symfony\Component\Uid\Ulid;

/** @psalm-api */
final readonly class DoctrineSnapshotStore implements SnapshotStore
{
    public function __construct(private EntityManagerInterface $em, private MessageSerializer $serializer) {}

    #[Override]
    public function save(PersistenceId $id, SnapshotEnvelope $snapshot): void
    {
        $entry = new SnapshotEntry(
            persistenceId: $id->toString(),
            sequenceNr: $snapshot->sequenceNr,
            stateType: $snapshot->stateType,
            stateData: $this->serializer->serialize($snapshot->state),
            timestamp: $snapshot->timestamp,
            writerId: (string) $snapshot->writerId,
        );

        $this->em->persist($entry);
        $this->em->flush();
    }

    #[Override]
    public function load(PersistenceId $id): ?SnapshotEnvelope
    {
        $result = $this->em->createQueryBuilder()
            ->select('s')
            ->from(SnapshotEntry::class, 's')
            ->where('s.persistenceId = :pid')
            ->orderBy('s.sequenceNr', 'DESC')
            ->setMaxResults(1)
            ->setParameter('pid', $id->toString())
            ->getQuery()
            ->getOneOrNullResult();

        if ($result === null) {
            return null;
        }

        assert($result instanceof SnapshotEntry);

        return new SnapshotEnvelope(
            persistenceId: $id,
            sequenceNr: $result->sequenceNr,
            state: $this->serializer->deserialize($result->stateData, $result->stateType),
            stateType: $result->stateType,
            timestamp: $result->timestamp,
            writerId: Ulid::fromString($result->writerId),
        );
    }

    #[Override]
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
