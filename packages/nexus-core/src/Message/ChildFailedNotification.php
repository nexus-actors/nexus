<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Message;

use Monadial\Nexus\Core\Actor\ActorRef;
use Throwable;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * @internal Internal system message: an escalating child sends this to its parent so
 * the parent's behavior observes the failure as a {@see \Monadial\Nexus\Core\Lifecycle\ChildFailed}
 * signal. Signals cannot travel through a mailbox directly (a mailbox only accepts the
 * actor's own message type or a SystemMessage), so the parent cell translates this
 * message into the signal. Not for direct use.
 */
final readonly class ChildFailedNotification implements SystemMessage
{
    /**
     * @param ActorRef<object> $child
     */
    public function __construct(public ActorRef $child, public Throwable $cause) {}
}
