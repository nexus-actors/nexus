<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Event;

use DateTimeImmutable;
use Monadial\Nexus\Persistence\PersistenceId;

readonly class EventEnvelope
{
    public function __construct(
        public PersistenceId $persistenceId,
        public int $sequenceNr,
        public object $event,
        public string $eventType,
        public DateTimeImmutable $timestamp,
        public array $metadata = [],
    ) {}

    public function withMetadata(array $metadata): self
    {
        return clone($this, ['metadata' => array_merge($this->metadata, $metadata)]);
    }
}
