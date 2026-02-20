<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Doctrine\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'nexus_event_journal')]
#[ORM\Index(name: 'idx_event_journal_pid', columns: ['persistence_id'])]
final class EventEntry
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'persistence_id', type: 'string', length: 255)]
        public private(set) string $persistenceId,
        #[ORM\Id]
        #[ORM\Column(name: 'sequence_nr', type: 'bigint')]
        public private(set) int $sequenceNr,
        #[ORM\Column(name: 'event_type', type: 'string', length: 255)]
        public private(set) string $eventType,
        #[ORM\Column(name: 'event_data', type: 'text')]
        public private(set) string $eventData,
        #[ORM\Column(name: 'timestamp', type: 'datetime_immutable')]
        public private(set) DateTimeImmutable $timestamp,
        #[ORM\Column(name: 'metadata', type: 'text', nullable: true)]
        public private(set) ?string $metadata = null,
    ) {}
}
