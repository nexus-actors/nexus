<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Async;

use Closure;
use Monadial\Nexus\Runtime\Exception\FutureCancelledException;
use Monadial\Nexus\Runtime\Exception\FutureException;
use Override;

/**
 * A FutureSlot that lazily evaluates a closure on await().
 * Used internally by Future combinators (map, flatMap).
 *
 * @template R of object
 * @implements FutureSlot<R>
 */
final class LazyFutureSlot implements FutureSlot
{
    /** @var ?R */
    private ?object $result = null;
    private ?FutureException $failure = null;
    private bool $resolved = false;
    private bool $cancelled = false;

    /** @var list<Closure(): void> */
    private array $cancelCallbacks = [];

    /** @param Closure(): R $computation */
    public function __construct(private readonly Closure $computation) {}

    #[Override]
    public function resolve(object $value): void
    {
        // LazyFutureSlot is not externally resolvable - it resolves itself on await()
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
    public function isResolved(): bool
    {
        return $this->resolved;
    }

    #[Override]
    /** @return R */
    public function await(): object
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        if ($this->cancelled) {
            throw new FutureCancelledException();
        }

        if (!$this->resolved) {
            $this->result = ($this->computation)();
            $this->resolved = true;
        }

        assert($this->result !== null);

        return $this->result;
    }
}
