<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Scheduler;

use Closure;

/**
 * An entry describing a named periodic schedule.
 *
 * @psalm-api
 */
readonly class ScheduleEntry
{
    /** @param Closure(): void $task */
    public function __construct(
        public string $name,
        public int $intervalSeconds,
        public Closure $task,
    ) {}
}
