<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Swoole;

use Monadial\Nexus\Runtime\Runtime\Cancellable;
use Override;
use Swoole\Timer;

/** @psalm-api */
final class SwooleCancellable implements Cancellable
{
    private bool $cancelled = false;

    public function __construct(private int $timerId) {}

    #[Override]
    public function cancel(): void
    {
        if (!$this->cancelled) {
            Timer::clear($this->timerId);
            $this->cancelled = true;
        }
    }

    #[Override]
    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    /**
     * Point the handle at the Swoole timer that now backs the schedule.
     *
     * Repeating schedules are two Swoole timers in sequence: an initial
     * `after` timer that hands over to a `tick` timer. The handle must always
     * clear the LIVE timer, so the runtime retargets it on the handover.
     *
     * @internal Used by SwooleRuntime only.
     */
    public function retarget(int $timerId): void
    {
        $this->timerId = $timerId;
    }
}
