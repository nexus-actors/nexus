<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Lifecycle;

use Monadial\Nexus\Core\Actor\UntracedMessage;

/**
 * Progress report from a ReceiverActor: N messages were routed and acked in
 * one poll tick. Consumed by the LifecycleWatchdog message-count threshold.
 *
 * @psalm-api
 */
final readonly class MessagesProcessed implements UntracedMessage
{
    public function __construct(public int $count) {}
}
