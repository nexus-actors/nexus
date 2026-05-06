<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Aggregate;

/**
 * @psalm-api
 *
 * Base for state-stored aggregates. Mutable state is allowed; recordThat() still
 * invokes applyXxx() so the same convention applies. Persistence strategies
 * (Doctrine ORM / DBAL) save the aggregate's state directly rather than the
 * event stream, but events flow through to the EventBus regardless.
 *
 * Marker subclass for type discrimination.
 */
abstract class StatefulAggregateRoot extends AggregateRoot {}
