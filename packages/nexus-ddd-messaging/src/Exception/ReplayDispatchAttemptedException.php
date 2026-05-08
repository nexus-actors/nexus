<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Exception;

/**
 * @psalm-api
 *
 * Thrown by `ReplayingContextStorage::push()` when application code
 * attempts to dispatch a message during event-sourced replay.
 */
final class ReplayDispatchAttemptedException extends MessagingException
{
    public static function whileReplaying(): self
    {
        return new self(
            'Cannot dispatch during ES replay — a handler or applyXxx method '
            . 'attempted to dispatch a message while the framework is rebuilding '
            . 'state from a persisted event stream.',
        );
    }
}
