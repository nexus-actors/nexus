<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Doctrine\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'nexus_durable_state')]
class DurableStateEntry
{
    #[ORM\Id]
    #[ORM\Column(name: 'persistence_id', type: 'string', length: 255)]
    public string $persistenceId;

    #[ORM\Column(name: 'revision', type: 'bigint')]
    public int $revision;

    #[ORM\Column(name: 'state_type', type: 'string', length: 255)]
    public string $stateType;

    #[ORM\Column(name: 'state_data', type: 'text')]
    public string $stateData;

    #[ORM\Column(name: 'timestamp', type: 'datetime_immutable')]
    public \DateTimeImmutable $timestamp;
}
