<?php
declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Swoole;

use Monadial\Nexus\Core\Actor\Cancellable;
use Override;

/**
 * Cancellable for timers scheduled before Co\run() starts.
 *
 * Holds a reference to a boolean flag shared with the pending timer closure.
 * When cancel() is called, the flag is set to true, preventing the timer
 * from being created when run() executes pending actions.
 *
 * @internal
 */
final class DeferredCancellable implements Cancellable
{
    /** @param bool $cancelled Shared reference to the cancelled flag */
    public function __construct(private bool &$cancelled) {}

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
