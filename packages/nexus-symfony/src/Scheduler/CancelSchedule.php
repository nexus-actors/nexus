<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Scheduler;

/**
 * Message sent to SchedulerActor to cancel a named schedule.
 *
 * @psalm-api
 */
readonly class CancelSchedule
{
    public function __construct(public string $name) {}
}
