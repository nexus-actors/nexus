<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Event\Attribute;

use Attribute;

/**
 * @psalm-api
 *
 * Marks an event class as tombstoned — present in history but no longer
 * applied to aggregate state on replay. The framework silently skips
 * tombstoned events during replay (with a debug-level log).
 *
 * Tombstoning a state-affecting event without a compensating upcaster
 * is a violation; use only for logging-only / never-applied-to-state
 * events. Tombstoning also breaks bit-identical projection rebuilds
 * (per umbrella spec v6 §10.3) — manual repair may be needed.
 *
 * `removedAt` is the framework-product version where the event was
 * deprecated (e.g., 'v3.2.0'); used in tooling/documentation only.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class TombstoneEvent
{
    /**
     * @param non-empty-string $name
     * @param non-empty-string $removedAt
     */
    public function __construct(public string $name, public string $removedAt) {}
}
