<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Monadial\Nexus\Persistence\Doctrine\Entity\DurableStateEntry;
use Monadial\Nexus\Persistence\Exception\ConcurrentModificationException;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\State\DurableStateEnvelope;
use Monadial\Nexus\Persistence\State\DurableStateStore;
use Monadial\Nexus\Serialization\MessageSerializer;
use Monadial\Nexus\Serialization\PhpNativeSerializer;
use Override;

/** @psalm-api */
final class DoctrineDurableStateStore implements DurableStateStore
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageSerializer $serializer = new PhpNativeSerializer(),
    ) {}

    #[Override]
    public function get(PersistenceId $id): ?DurableStateEnvelope
    {
        $entry = $this->em->find(DurableStateEntry::class, $id->toString());

        if ($entry === null) {
            return null;
        }

        return new DurableStateEnvelope(
            persistenceId: $id,
            version: $entry->version,
            state: $this->serializer->deserialize($entry->stateData, $entry->stateType),
            stateType: $entry->stateType,
            timestamp: $entry->timestamp,
            writerId: $entry->writerId,
        );
    }

    #[Override]
    public function upsert(PersistenceId $id, DurableStateEnvelope $state): void
    {
        $entry = $this->em->find(DurableStateEntry::class, $id->toString());

        if ($entry === null) {
            $entry = new DurableStateEntry(
                persistenceId: $id->toString(),
                stateType: $state->stateType,
                stateData: $this->serializer->serialize($state->state),
                timestamp: $state->timestamp,
                writerId: $state->writerId,
            );
        } else {
            $entry->update(
                $state->stateType,
                $this->serializer->serialize($state->state),
                $state->timestamp,
                $state->writerId,
            );
        }

        $this->em->persist($entry);

        try {
            $this->em->flush();
        } catch (OptimisticLockException $e) {
            throw new ConcurrentModificationException(
                $id,
                $state->version - 1,
                "Optimistic lock failed for persistence ID '{$id->toString()}'",
                $e,
            );
        }
    }

    #[Override]
    public function delete(PersistenceId $id): void
    {
        $entry = $this->em->find(DurableStateEntry::class, $id->toString());

        if ($entry !== null) {
            $this->em->remove($entry);
            $this->em->flush();
        }
    }
}
