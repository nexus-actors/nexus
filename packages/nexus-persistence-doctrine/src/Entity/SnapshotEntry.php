<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Doctrine\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'nexus_snapshot_store')]
class SnapshotEntry
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'persistence_id', type: 'string', length: 255)]
        public private(set) string $persistenceId,

        #[ORM\Id]
        #[ORM\Column(name: 'sequence_nr', type: 'bigint')]
        public private(set) int $sequenceNr,

        #[ORM\Column(name: 'state_type', type: 'string', length: 255)]
        public private(set) string $stateType,

        #[ORM\Column(name: 'state_data', type: 'text')]
        public private(set) string $stateData,

        #[ORM\Column(name: 'timestamp', type: 'datetime_immutable')]
        public private(set) \DateTimeImmutable $timestamp,
    ) {}
}
