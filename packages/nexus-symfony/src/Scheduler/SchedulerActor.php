<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Scheduler;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorHandler;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Runtime\Cancellable;
use Override;

/**
 * Manages cron-style scheduled tasks for a worker.
 *
 * Long-lived actors register named schedules via RegisterSchedule.
 * The scheduler spawns repeating timers via ActorContext::scheduleRepeatedly().
 * Tasks run in the current worker's coroutine event loop.
 *
 * Typically pinned to worker 0 via #[Actor(Isolated, 'scheduler')].
 *
 * @implements ActorHandler<object>
 */
final class SchedulerActor implements ActorHandler
{
    /** @var array<string, Cancellable> */
    private array $timers = [];

    #[Override]
    public function handle(ActorContext $ctx, object $message): Behavior
    {
        if ($message instanceof RegisterSchedule) {
            return $this->register($ctx, $message->entry);
        }

        if ($message instanceof CancelSchedule) {
            return $this->cancel($message->name);
        }

        return Behavior::unhandled();
    }

    private function register(ActorContext $ctx, ScheduleEntry $entry): Behavior
    {
        if (isset($this->timers[$entry->name])) {
            $this->timers[$entry->name]->cancel();
        }

        $interval = Duration::seconds($entry->intervalSeconds);
        $task     = $entry->task;

        $this->timers[$entry->name] = $ctx->scheduleRepeatedly(
            $interval,
            $interval,
            static function () use ($task): void {
                ($task)();
            },
        );

        return Behavior::same();
    }

    private function cancel(string $name): Behavior
    {
        if (isset($this->timers[$name])) {
            $this->timers[$name]->cancel();
            unset($this->timers[$name]);
        }

        return Behavior::same();
    }
}
