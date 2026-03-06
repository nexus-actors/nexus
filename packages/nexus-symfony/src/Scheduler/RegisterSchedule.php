<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Scheduler;

/**
 * Message sent to SchedulerActor to register a named schedule.
 *
 * @psalm-api
 */
readonly class RegisterSchedule
{
    public function __construct(public ScheduleEntry $entry) {}
}
