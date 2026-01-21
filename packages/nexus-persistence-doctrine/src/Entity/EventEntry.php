<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Doctrine\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'nexus_event_journal')]
#[ORM\Index(columns: ['persistence_id'], name: 'idx_event_journal_pid')]
class EventEntry
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 255)]
    public string $persistenceId;

    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    public int $sequenceNr;

    #[ORM\Column(type: 'string', length: 255)]
    public string $eventType;

    #[ORM\Column(type: 'text')]
    public string $eventData;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $metadata = null;

    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $timestamp;
}
