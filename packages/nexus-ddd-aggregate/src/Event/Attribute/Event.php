<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Event\Attribute;

use Attribute;

/**
 * @psalm-api
 *
 * Decorates `DomainEvent` classes with their stable wire-format identity.
 *
 * The persisted event store carries `(name, version)` — never the PHP class
 * name. Renames don't affect history (provided `name` stays stable).
 * `EventNameRegistry::scan` enforces `(name, version)` uniqueness at boot.
 *
 * Default version is 1; bump it whenever the event's payload shape
 * changes in a backwards-incompatible way and ship an `Upcaster` for the
 * old version (see Phase 6).
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Event
{
    /**
     * @param non-empty-string $name
     * @param positive-int     $version
     */
    public function __construct(public string $name, public int $version = 1) {}
}
