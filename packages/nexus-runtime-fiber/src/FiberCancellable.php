<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Fiber;

use Monadial\Nexus\Core\Actor\Cancellable;

final class FiberCancellable implements Cancellable
{
    private bool $cancelled = false;

    public function cancel(): void
    {
        $this->cancelled = true;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }
}
