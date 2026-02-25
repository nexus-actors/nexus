<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Event;

use DateTimeImmutable;
use Monadial\Nexus\Persistence\PersistenceId;

/** @psalm-api */
final readonly class EventEnvelope
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public PersistenceId $persistenceId,
        public int $sequenceNr,
        public object $event,
        public string $eventType,
        public DateTimeImmutable $timestamp,
        public string $writerId = '',
        public array $metadata = [],
    ) {}

    /** @param array<string, mixed> $metadata */
    public function withMetadata(array $metadata): self
    {
        return clone($this, ['metadata' => array_merge($this->metadata, $metadata)]);
    }
}
