<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Async;

use Closure;
use Monadial\Nexus\Runtime\Exception\FutureException;
use Override;

/**
 * A FutureSlot that is already settled at construction time.
 * Used by Future::resolved() and Future::failed() factories.
 *
 * @template R of object
 * @implements FutureSlot<R>
 */
final class ImmediateFutureSlot implements FutureSlot
{
    /** @var ?R */
    private ?object $value = null;
    private ?FutureException $error = null;
    private bool $resolved = false;

    /** @param R $value */
    #[Override]
    public function resolve(object $value): void
    {
        $this->value = $value;
        $this->resolved = true;
    }

    #[Override]
    public function fail(FutureException $e): void
    {
        $this->error = $e;
        $this->resolved = true;
    }

    #[Override]
    public function cancel(): void
    {
        // No-op: already-settled slots cannot be cancelled.
    }

    #[Override]
    public function onCancel(Closure $callback): void
    {
        // No-op: already-settled slots cannot transition to cancelled state.
    }

    #[Override]
    public function isResolved(): bool
    {
        return $this->resolved;
    }

    /** @return R */
    #[Override]
    public function await(): object
    {
        if ($this->error !== null) {
            throw $this->error;
        }

        assert($this->value !== null);

        return $this->value;
    }
}
