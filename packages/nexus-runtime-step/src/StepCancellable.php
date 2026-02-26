<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Step;

use Monadial\Nexus\Runtime\Runtime\Cancellable;
use Override;

/** @psalm-api */
final class StepCancellable implements Cancellable
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
