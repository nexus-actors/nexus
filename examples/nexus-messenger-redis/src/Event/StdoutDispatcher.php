<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\MessengerRedis\Event;

use Monadial\Nexus\Messenger\Event\MessageConsumed;
use Monadial\Nexus\Messenger\Event\WorkerRecyclingTriggered;
use Psr\EventDispatcher\EventDispatcherInterface;

use function get_class;
use function sprintf;

/**
 * Minimal PSR-14 dispatcher that prints bridge events to stdout.
 *
 * Two events matter for this showcase:
 *  - MessageConsumed  — fires after each broker message is acked.
 *  - WorkerRecyclingTriggered — fires when the LifecycleWatchdog decides the
 *    worker has processed enough messages and initiates a graceful shutdown.
 *
 * In production you would replace this with your framework's dispatcher
 * (Symfony EventDispatcher, Laravel's EventDispatcher, etc.).
 */
final class StdoutDispatcher implements EventDispatcherInterface
{
    public function dispatch(object $event): object
    {
        if ($event instanceof MessageConsumed) {
            echo sprintf(
                "[PSR-14] MessageConsumed  target=%s  message=%s\n",
                $event->targetPath,
                $event->message::class,
            );
        } elseif ($event instanceof WorkerRecyclingTriggered) {
            echo sprintf(
                "[PSR-14] WorkerRecyclingTriggered  reason=\"%s\"\n",
                $event->reason,
            );
        } else {
            echo sprintf("[PSR-14] %s\n", get_class($event));
        }

        return $event;
    }
}
