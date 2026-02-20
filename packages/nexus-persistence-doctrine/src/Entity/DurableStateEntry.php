<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Doctrine\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'nexus_durable_state')]
final class DurableStateEntry
{
    #[ORM\Column(name: 'version', type: 'integer')]
    #[ORM\Version]
    public private(set) int $version = 1;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'persistence_id', type: 'string', length: 255)]
        public private(set) string $persistenceId,
        #[ORM\Column(name: 'state_type', type: 'string', length: 255)]
        public private(set) string $stateType,
        #[ORM\Column(name: 'state_data', type: 'text')]
        public private(set) string $stateData,
        #[ORM\Column(name: 'timestamp', type: 'datetime_immutable')]
        public private(set) DateTimeImmutable $timestamp,
    ) {}

    public function update(string $stateType, string $stateData, DateTimeImmutable $timestamp): void
    {
        $this->stateType = $stateType;
        $this->stateData = $stateData;
        $this->timestamp = $timestamp;
    }
}
