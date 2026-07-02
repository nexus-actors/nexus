<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

/**
 * @psalm-api
 *
 * Internal lifecycle state machine for actors.
 *
 * @internal Implementation detail of {@see ActorSystem::spawn()}. Not for direct use.
 */
enum ActorState: string
{
    case New = 'new';
    case Starting = 'starting';
    case Running = 'running';
    case Suspended = 'suspended';
    case Stopping = 'stopping';
    case Stopped = 'stopped';

    /**
     * Returns true if a transition from this state to the target state is valid.
     *
     * Valid transitions:
     *   New -> Starting
     *   Starting -> Running
     *   Running -> Suspended, Stopping, Starting (supervised restart)
     *   Suspended -> Running, Stopping, Starting (supervised restart)
     *   Stopping -> Stopped
     */
    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::New => $target === self::Starting,
            self::Starting => $target === self::Running,
            self::Running => $target === self::Suspended
                || $target === self::Stopping
                || $target === self::Starting,
            self::Suspended => $target === self::Running
                || $target === self::Stopping
                || $target === self::Starting,
            self::Stopping => $target === self::Stopped,
            self::Stopped => false,
        };
    }
}
