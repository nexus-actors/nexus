<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Ask;

use Monadial\Nexus\Messenger\Exception\AskCapacityExceededException;
use Monadial\Nexus\Runtime\Async\FutureSlot;

/**
 * Single-threaded registry for pending ask replies.
 *
 * Implements first-reply-wins semantics: only the first resolve() call
 * will deliver to the slot. Subsequent resolves on the same correlation ID
 * return false (late/duplicate replies are dropped).
 *
 * @psalm-api
 */
final class PendingAskRegistry
{
    /** @var array<string, FutureSlot> */
    private array $slots = [];

    public function __construct(private readonly int $maxPending = 10_000) {}

    /**
     * Register a future slot for a correlation ID.
     *
     * @throws AskCapacityExceededException when at capacity
     */
    public function register(string $correlationId, FutureSlot $slot): void
    {
        if (\count($this->slots) >= $this->maxPending) {
            throw new AskCapacityExceededException($this->maxPending, \count($this->slots));
        }

        $this->slots[$correlationId] = $slot;
    }

    /**
     * Resolve the future with a reply. First reply wins.
     *
     * Returns false for unknown IDs or late/duplicate replies.
     * Once resolved, the slot is removed.
     */
    public function resolve(string $correlationId, object $reply): bool
    {
        if (!isset($this->slots[$correlationId])) {
            return false;
        }

        $slot = $this->slots[$correlationId];
        unset($this->slots[$correlationId]);
        $slot->resolve($reply);

        return true;
    }

    /**
     * Remove a pending ask without resolving.
     *
     * Used for timeout paths. Returns the slot or null if not found.
     */
    public function remove(string $correlationId): FutureSlot|null
    {
        if (!isset($this->slots[$correlationId])) {
            return null;
        }

        $slot = $this->slots[$correlationId];
        unset($this->slots[$correlationId]);

        return $slot;
    }

    /**
     * Return the number of pending asks.
     */
    public function count(): int
    {
        return \count($this->slots);
    }
}
