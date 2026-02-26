<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Step;

use Closure;
use Fiber;
use Monadial\Nexus\Runtime\Async\FutureSlot;
use Monadial\Nexus\Runtime\Exception\FutureCancelledException;
use Monadial\Nexus\Runtime\Exception\FutureException;
use Override;

/**
 * Deterministic FutureSlot for StepRuntime.
 *
 * In Step, all processing is driven by explicit step()/drain() calls.
 * The slot suspends the fiber on await() and resumes when resolved.
 *
 * @implements FutureSlot<object>
 */
final class StepFutureSlot implements FutureSlot
{
    private ?object $result = null;
    private ?FutureException $failure = null;
    private bool $resolved = false;
    private bool $cancelled = false;

    /** @var list<Closure(): void> */
    private array $cancelCallbacks = [];

    #[Override]
    public function resolve(object $value): void
    {
        if ($this->resolved) {
            return;
        }

        $this->result = $value;
        $this->resolved = true;
    }

    #[Override]
    public function fail(FutureException $e): void
    {
        if ($this->resolved) {
            return;
        }

        $this->failure = $e;
        $this->resolved = true;
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
