<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Step;

use Fiber;
use InvalidArgumentException;
use Monadial\Nexus\Runtime\Async\FutureSlot;
use Monadial\Nexus\Runtime\Exception\FutureException;
use Override;
use Throwable;

/**
 * Deterministic FutureSlot for StepRuntime.
 *
 * In Step, all processing is driven by explicit step()/drain() calls.
 * The slot suspends the fiber on await() and resumes when resolved.
 *
 * @implements FutureSlot<object, FutureException>
 */
final class StepFutureSlot implements FutureSlot
{
    private ?object $result = null;
    private ?FutureException $failure = null;
    private bool $resolved = false;

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
    public function fail(Throwable $e): void
    {
        if ($this->resolved) {
            return;
        }

        if (!$e instanceof FutureException) {
            throw new InvalidArgumentException('Future failure must implement FutureException', previous: $e);
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
