<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Snapshot\Hook;

use Monadial\Nexus\Ddd\Aggregate\Hook\HookEvent;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Intermediate base for SnapshotStore lifecycle events
 * (load / save / delete + their before/after/failure variants).
 * Type-discrimination only — no extra fields beyond HookEvent's
 * `streamId`.
 */
abstract readonly class SnapshotHookEvent extends HookEvent {}
