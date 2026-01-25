<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Doctrine\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'nexus_snapshot_store')]
class SnapshotEntry
{
    #[ORM\Id]
    #[ORM\Column(name: 'persistence_id', type: 'string', length: 255)]
    public string $persistenceId;

    #[ORM\Id]
    #[ORM\Column(name: 'sequence_nr', type: 'bigint')]
    public int $sequenceNr;

    #[ORM\Column(name: 'state_type', type: 'string', length: 255)]
    public string $stateType;

    #[ORM\Column(name: 'state_data', type: 'text')]
    public string $stateData;

    #[ORM\Column(name: 'timestamp', type: 'datetime_immutable')]
    public \DateTimeImmutable $timestamp;
}
