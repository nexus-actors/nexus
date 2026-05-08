<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Versioning;

use DateTimeImmutable;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Read-only context passed to upcasters. Subset of the persisted envelope's
 * metadata that upcasters legitimately need (event name + the version
 * they're transforming FROM + occurredAt for time-aware upcasts).
 *
 * Local to nexus-ddd-aggregate so the package stays independent of
 * nexus-ddd-messaging (where the broader `MessageMetadata` lives).
 * `EventSourcingStrategy` constructs the context from the persisted
 * envelope's metadata at replay time.
 */
final readonly class PayloadContext
{
    public function __construct(
        public string $eventName,
        public int $fromVersion,
        public DateTimeImmutable $occurredAt,
    ) {}
}
