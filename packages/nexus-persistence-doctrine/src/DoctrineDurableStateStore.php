<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Monadial\Nexus\Persistence\Doctrine\Entity\DurableStateEntry;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\State\DurableStateEnvelope;
use Monadial\Nexus\Persistence\State\DurableStateStore;

final class DoctrineDurableStateStore implements DurableStateStore
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function get(PersistenceId $id): ?DurableStateEnvelope
    {
        $entry = $this->em->find(DurableStateEntry::class, $id->toString());

        if ($entry === null) {
            return null;
        }

        return new DurableStateEnvelope(
            persistenceId: $id,
            revision: (int) $entry->revision,
            state: unserialize($entry->stateData),
            stateType: $entry->stateType,
            timestamp: $entry->timestamp,
        );
    }

    public function upsert(PersistenceId $id, DurableStateEnvelope $state): void
    {
        $entry = $this->em->find(DurableStateEntry::class, $id->toString());

        if ($entry === null) {
            $entry = new DurableStateEntry();
            $entry->persistenceId = $id->toString();
        }

        $entry->revision = $state->revision;
        $entry->stateType = $state->stateType;
        $entry->stateData = serialize($state->state);
        $entry->timestamp = $state->timestamp;

        $this->em->persist($entry);
        $this->em->flush();
    }

    public function delete(PersistenceId $id): void
    {
        $entry = $this->em->find(DurableStateEntry::class, $id->toString());

        if ($entry !== null) {
            $this->em->remove($entry);
            $this->em->flush();
        }
    }
}
