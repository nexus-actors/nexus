<?php
declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Swoole;

use Monadial\Nexus\Core\Actor\Cancellable;
use Override;
use Swoole\Timer;

/** @psalm-api */
final class SwooleCancellable implements Cancellable
{
    private bool $cancelled = false;

    public function __construct(private readonly int $timerId)
    {
    }

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
}
