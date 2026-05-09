<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Versioning;

use DateTimeImmutable;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Read-only context passed to upcasters. Carries the diagnostic /
 * time-aware fields the upcaster body may legitimately read. The
 * upcaster's typed input event already encodes its own `(eventName,
 * version)` via its `#[Event]` attribute; the context exists only for
 * data that's NOT in the event itself.
 *
 * Local to nexus-ddd-aggregate so the package stays independent of
 * nexus-ddd-messaging (where the broader `MessageMetadata` lives).
 * `EventSourcingStrategy` constructs the context from the persisted
 * envelope's metadata at replay time.
 */
final readonly class UpcastContext
{
    public function __construct(
        public string $eventName,
        public int $fromVersion,
        public DateTimeImmutable $occurredAt,
    ) {}
}
