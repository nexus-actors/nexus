<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Fiber;

use Closure;
use Fiber;
use Monadial\Nexus\Core\Async\FutureSlot;
use Override;
use Throwable;

/**
 * Fiber-based FutureSlot implementation.
 *
 * Suspends the calling fiber on await(). Resumes it when resolve() or fail() is called.
 * Uses a wakeup callback to signal the runtime that a fiber needs resumption.
 */
final class FiberFutureSlot implements FutureSlot
{
    private ?object $result = null;
    private ?Throwable $failure = null;
    private bool $resolved = false;

    /**
     * @param Closure(): void $onResolve Callback to signal the runtime (sets wakeupPending)
     */
    public function __construct(private readonly Closure $onResolve) {}

    #[Override]
    public function resolve(object $value): void
    {
        if ($this->resolved) {
            return;
        }

        $this->result = $value;
        $this->resolved = true;
        ($this->onResolve)();
    }

    #[Override]
    public function fail(Throwable $e): void
    {
        if ($this->resolved) {
            return;
        }

        $this->failure = $e;
        $this->resolved = true;
        ($this->onResolve)();
    }

    #[Override]
    public function isResolved(): bool
    {
        return $this->resolved;
    }

    #[Override]
    public function await(): object
    {
        while (!$this->resolved) {
            Fiber::suspend('future_wait');
        }

        if ($this->failure !== null) {
            throw $this->failure;
        }

        assert($this->result !== null);

        return $this->result;
    }
}
