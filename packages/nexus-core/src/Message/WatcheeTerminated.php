<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Message;

use Monadial\Nexus\Core\Actor\ActorRef;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * @internal Internal system message that carries a death-watch notification into a
 * watcher's mailbox. The receiving cell translates it into a {@see \Monadial\Nexus\Core\Lifecycle\Terminated}
 * signal delivered to the behavior's signal handler — signals cannot be enqueued
 * directly because a mailbox only accepts the actor's own message type or a
 * SystemMessage. Not for direct use.
 */
final readonly class WatcheeTerminated implements SystemMessage
{
    /**
     * @param ActorRef<object> $subject the actor that terminated
     */
    public function __construct(public ActorRef $subject) {}
}
