<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Closure;
use Override;
use Throwable;

/**
 * A FutureSlot that lazily evaluates a closure on await().
 * Used internally by Future combinators (map, flatMap).
 */
final class LazyFutureSlot implements FutureSlot
{
    private ?object $result = null;
    private bool $resolved = false;

    /** @param Closure(): object $computation */
    public function __construct(private readonly Closure $computation) {}

    #[Override]
    public function resolve(object $value): void
    {
        // LazyFutureSlot is not externally resolvable — it resolves itself on await()
    }

    #[Override]
    public function fail(Throwable $e): void
    {
        // LazyFutureSlot is not externally failable — failures propagate through the closure
    }

    #[Override]
    public function isResolved(): bool
    {
        return $this->resolved;
    }

    #[Override]
    public function await(): object
    {
        if (!$this->resolved) {
            $this->result = ($this->computation)();
            $this->resolved = true;
        }

        assert($this->result !== null);

        return $this->result;
    }
}
