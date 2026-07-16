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
 * timer is never created.
 *
 * @internal
 */
final class DeferredCancellable implements Cancellable
{
    private bool $cancelled = false;

    #[Override]
    public function cancel(): void
    {
        $this->cancelled = true;
    }

    #[Override]
    public function isCancelled(): bool
    {
        return $this->cancelled;
    }
}
