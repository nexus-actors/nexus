<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Event\Hook;

use Monadial\Nexus\Ddd\Aggregate\Hook\HookEvent;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Intermediate base for VersionedEventStore lifecycle events
 * (load / append / delete + their before/after/failure variants).
 * Type-discrimination only — no extra fields beyond HookEvent's
 * `streamId`. Listeners use `function(EventStoreHookEvent $e)` to
 * subscribe to all event-store hooks while ignoring snapshot ones.
 */
abstract readonly class EventStoreHookEvent extends HookEvent {}
