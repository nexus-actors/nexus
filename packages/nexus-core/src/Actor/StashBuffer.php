<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Monadial\Nexus\Core\Exception\StashOverflowException;
use Monadial\Nexus\Core\Mailbox\Envelope;

/**
 * @psalm-api
 *
 * Bounded stash buffer for actors.
 *
 * Stashed envelopes are replayed inline when unstashAll() is called,
 * guaranteeing that stashed messages are processed before new messages.
 *
 * @template T of object
 */
interface StashBuffer
{
    /**
     * Stash the given envelope for later replay.
     *
     * @throws StashOverflowException if the buffer is full
     */
    public function stash(Envelope $envelope): void;

    /**
     * Replay all stashed envelopes through the target behavior, then continue with it.
     *
     * If the buffer is empty, returns the target behavior directly.
     * Otherwise, returns a special behavior that ActorCell will recognize and replay inline.
     *
     * @param Behavior<T> $targetBehavior
     * @return Behavior<T>
     */
    public function unstashAll(Behavior $targetBehavior): Behavior;

    public function isEmpty(): bool;

    public function isFull(): bool;

    public function size(): int;

    public function capacity(): int;
}
