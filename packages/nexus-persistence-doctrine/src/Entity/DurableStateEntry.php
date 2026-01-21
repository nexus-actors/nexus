<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Doctrine\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'nexus_durable_state')]
class DurableStateEntry
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 255)]
    public string $persistenceId;

    #[ORM\Column(type: 'bigint')]
    public int $revision;

    #[ORM\Column(type: 'string', length: 255)]
    public string $stateType;

    #[ORM\Column(type: 'text')]
    public string $stateData;

    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $timestamp;
}
