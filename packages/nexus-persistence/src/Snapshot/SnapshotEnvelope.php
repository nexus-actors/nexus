<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Snapshot;

use DateTimeImmutable;
use Monadial\Nexus\Persistence\PersistenceId;
use Symfony\Component\Uid\Ulid;

/** @psalm-api */
final readonly class SnapshotEnvelope
{
    public function __construct(
        public PersistenceId $persistenceId,
        public int $sequenceNr,
        public object $state,
        public string $stateType,
        public DateTimeImmutable $timestamp,
        public Ulid $writerId = new Ulid(),
    ) {}
}
