<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Tests\Support;

use Closure;
use Monadial\Nexus\Runtime\Async\FutureSlot;
use Monadial\Nexus\Runtime\Exception\FutureException;
use Override;

/**
 * Test double for FutureSlot. Records resolved values and failures.
 *
 * @template R of object
 * @implements FutureSlot<R>
 */
final class RecordingFutureSlot implements FutureSlot
{
    private bool $resolved = false;

    /** @var R|null */
    private object|null $resolvedValue = null;
    private FutureException|null $failure = null;
    private bool $cancelled = false;

    /** @var list<Closure(): void> */
    private array $cancelCallbacks = [];

    /**
     * @param R $value
     */
    #[Override]
    public function resolve(object $value): void
    {
        if (!$this->resolved && !$this->cancelled) {
            $this->resolved = true;
            $this->resolvedValue = $value;
        }
    }

    #[Override]
    public function fail(FutureException $e): void
    {
        if (!$this->resolved && !$this->cancelled) {
            $this->resolved = true;
            $this->failure = $e;
        }
    }

    #[Override]
    public function cancel(): void
    {
        if (!$this->resolved) {
            $this->cancelled = true;
            $this->resolved = true;

            foreach ($this->cancelCallbacks as $callback) {
                $callback();
            }
        }
    }

    #[Override]
    public function onCancel(Closure $callback): void
    {
        $this->cancelCallbacks[] = $callback;
    }

    #[Override]
    public function isResolved(): bool
    {
        return $this->resolved;
    }

    /**
     * @return R
     */
    #[Override]
    public function await(): object
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        if ($this->resolvedValue === null) {
            throw new FutureException('Future not resolved');
        }

        return $this->resolvedValue;
    }

    /**
     * Test helper: get the resolved value.
     *
     * @return R|null
     */
    public function getResolvedValue(): object|null
    {
        return $this->resolvedValue;
    }

    /**
     * Test helper: get the failure exception.
     */
    public function getFailure(): FutureException|null
    {
        return $this->failure;
    }

    /**
     * Test helper: check if cancelled.
     */
    public function isCancelled(): bool
    {
        return $this->cancelled;
    }
}
