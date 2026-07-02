<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Mailbox;

/**
 * Immutable value object configuring the capacity and overflow behaviour of an actor mailbox.
 *
 * Pass a `MailboxConfig` to `Props::withMailbox()` to tune how messages are queued for a
 * specific actor. The default (unbounded) is suitable for most actors; use bounded mailboxes
 * to apply back-pressure or drop policies on high-throughput pipelines.
 *
 * Example:
 * ```php
 * // Drop the newest message when the mailbox is full (circuit-breaker pattern)
 * $props = Props::fromBehavior($behavior)
 *     ->withMailbox(MailboxConfig::bounded(100, OverflowStrategy::DropNewest));
 *
 * // Unbounded queue — messages are never dropped (default)
 * $props = Props::fromBehavior($behavior)
 *     ->withMailbox(MailboxConfig::unbounded());
 * ```
 *
 * @see Props::withMailbox()    for attaching a config to an actor
 * @see OverflowStrategy        for the available overflow policies
 * @see Runtime::createMailbox() for the runtime that instantiates the physical mailbox
 *
 * @psalm-api
 * @psalm-immutable
 */
final readonly class MailboxConfig
{
    private function __construct(public int $capacity, public OverflowStrategy $strategy, public bool $bounded) {}

    /**
     * Create a bounded mailbox that enforces a maximum queue depth.
     *
     * When the mailbox reaches `$capacity`, `$strategy` determines what happens to
     * incoming messages (throw, drop newest, drop oldest, or back-pressure the sender).
     */
    public static function bounded(int $capacity, OverflowStrategy $strategy = OverflowStrategy::ThrowException): self
    {
        return new self($capacity, $strategy, true);
    }

    /**
     * Create an unbounded mailbox with no capacity limit.
     *
     * The queue grows without bound; only use this when message producers are naturally
     * rate-limited or when unbounded memory growth is acceptable.
     */
    public static function unbounded(): self
    {
        return new self(PHP_INT_MAX, OverflowStrategy::ThrowException, false);
    }

    /**
     * Return a new config with the given capacity, preserving all other settings.
     */
    public function withCapacity(int $capacity): self
    {
        return clone($this, ['capacity' => $capacity]);
    }

    /**
     * Return a new config with the given overflow strategy, preserving all other settings.
     */
    public function withStrategy(OverflowStrategy $strategy): self
    {
        return clone($this, ['strategy' => $strategy]);
    }
}
