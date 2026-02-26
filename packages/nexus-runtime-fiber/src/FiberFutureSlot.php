<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Fiber;

use Closure;
use Fiber;
use Monadial\Nexus\Runtime\Async\FutureSlot;
use Monadial\Nexus\Runtime\Exception\FutureCancelledException;
use Monadial\Nexus\Runtime\Exception\FutureException;
use Override;

/**
 * Fiber-based FutureSlot implementation.
 *
 * Suspends the calling fiber on await(). Resumes it when resolve() or fail() is called.
 * Uses a wakeup callback to signal the runtime that a fiber needs resumption.
 *
 * @implements FutureSlot<object>
 */
final class FiberFutureSlot implements FutureSlot
{
    private ?object $result = null;
    private ?FutureException $failure = null;
    private bool $resolved = false;
    private bool $cancelled = false;

    /** @var list<Closure(): void> */
    private array $cancelCallbacks = [];

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
    public function fail(FutureException $e): void
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
    public function cancel(): void
    {
        if ($this->resolved) {
            return;
        }

        $this->cancelled = true;
        $this->resolved = true;

        foreach ($this->cancelCallbacks as $callback) {
            $callback();
        }

        ($this->onResolve)();
    }

    #[Override]
    public function onCancel(Closure $callback): void
    {
        $this->cancelCallbacks[] = $callback;
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

        if ($this->cancelled) {
            throw new FutureCancelledException();
        }

        assert($this->result !== null);

        return $this->result;
    }
}
