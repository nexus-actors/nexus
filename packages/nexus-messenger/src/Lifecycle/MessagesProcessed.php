<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Lifecycle;

/**
 * Progress report from a ReceiverActor: N messages were routed and acked in
 * one poll tick. Consumed by the LifecycleWatchdog message-count threshold.
 *
 * @psalm-api
 */
final readonly class MessagesProcessed
{
    public function __construct(public int $count)
    {
    }
}
