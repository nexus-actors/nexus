<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\State;

use DateTimeImmutable;
use Monadial\Nexus\Persistence\PersistenceId;

readonly class DurableStateEnvelope
{
    public function __construct(
        public PersistenceId $persistenceId,
        public int $version,
        public object $state,
        public string $stateType,
        public DateTimeImmutable $timestamp,
    ) {
    }
}
