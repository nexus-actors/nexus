<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Event;

/**
 * PSR-14 event dispatched by the LifecycleWatchdog just before it triggers a
 * graceful ActorSystem shutdown because a lifecycle threshold was breached.
 *
 * @psalm-api
 */
final readonly class WorkerRecyclingTriggered
{
    public function __construct(public string $reason)
    {
    }
}
