<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Aggregate;

/**
 * @psalm-api
 *
 * Base for event-sourced aggregates. State is reconstructed by replaying events
 * via the inherited replay() method. Subclasses define applyXxx() methods that
 * MUST be pure (no I/O, no recordThat(), no clock, no logging).
 *
 * This is a marker subclass — it inherits all behavior from AggregateRoot.
 * Persistence strategies discriminate on this type vs StatefulAggregateRoot
 * to choose event-sourced vs state-stored persistence.
 */
abstract class EventSourcedAggregateRoot extends AggregateRoot {}
