<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Fiber;

use Monadial\Nexus\Core\Actor\Cancellable;
use Override;

/** @psalm-api */
final class FiberCancellable implements Cancellable
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
