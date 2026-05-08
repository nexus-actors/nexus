<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Exception;

/**
 * @psalm-api
 *
 * Thrown by `EventSourcedAggregateRoot::recordThat` when called from
 * inside an `apply()` method during replay. apply() is supposed to be
 * a pure (state, event) → state reduction; calling recordThat() from
 * inside apply() during replay would re-emit historical events,
 * corrupting the stream.
 *
 * If you see this exception, an apply method is dispatching events
 * (directly or indirectly) — that's a bug. apply() must be pure.
 */
final class ApplyDuringReplayException extends NexusDddException
{
    public static function inApplyMethod(): self
    {
        return new self(
            'Cannot recordThat() during replay — apply() methods must be '
            . 'pure (state, event) → state reductions and must not emit '
            . 'further events. Move the event-emission to a command method.',
        );
    }
}
