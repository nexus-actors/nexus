<?php
declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Support;

use Monadial\Nexus\Core\Actor\Cancellable;

final class TestCancellable implements Cancellable
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
