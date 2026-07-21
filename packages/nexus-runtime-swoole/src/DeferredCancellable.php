<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Swoole;

use Monadial\Nexus\Runtime\Runtime\Cancellable;
use Override;

/**
 * Cancellable for timers scheduled before Co\run() starts.
 *
 * The pending timer closure captures this instance and checks isCancelled()
 * when run() executes pending actions. When cancel() is called first, the
 * timer is never created. Once run() materialises the real timer, the pending
 * closure attaches its handle here via materialize(), and cancel() delegates
 * to it — cancelling after run() clears the live Swoole timer.
 *
 * @internal
 */
final class DeferredCancellable implements Cancellable
{
    private bool $cancelled = false;

    private ?Cancellable $inner = null;

    #[Override]
    public function cancel(): void
    {
        if ($this->cancelled) {
            return;
        }

        $this->cancelled = true;
        $this->inner?->cancel();
    }

    #[Override]
    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    /**
     * Attach the real timer handle created when run() replays the pending
     * schedule, so later cancellation reaches the live Swoole timer.
     *
     * @internal Used by SwooleRuntime only.
     */
    public function materialize(Cancellable $inner): void
    {
        $this->inner = $inner;

        if ($this->cancelled) {
            $inner->cancel();
        }
    }
}
