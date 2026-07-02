<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\EventSourced;

use Closure;
use Monadial\Nexus\Core\Actor\ActorRef;

/**
 * Command-handler return type for event-sourced actors.
 *
 * An `Effect` encodes what the persistence engine should do in response to a
 * command: persist one or more events, reply to a caller, stash the message for
 * later processing, stop the actor, or do nothing. The engine executes the effect
 * after the command handler returns, guaranteeing that side-effects registered via
 * `thenRun()` or `thenReply()` only execute once the events are durably stored.
 *
 * Example:
 * ```php
 * // Persist an event and then reply with the updated state
 * return Effect::persist(new ItemAdded($item))
 *     ->thenReply($ctx->sender(), fn(CartState $state) => new CartUpdated($state->items));
 *
 * // Do nothing (read-only query)
 * return Effect::none()->thenReply($replyTo, fn(CartState $s) => new CartSnapshot($s));
 *
 * // Stop the actor after persisting a terminal event
 * return Effect::persist(new CartCheckedOut())->thenRun(fn() => Effect::stop());
 * ```
 *
 * @see EventSourcedBehavior for wiring the command handler that produces Effects
 * @see EffectType for the enumeration of available effect kinds
 *
 * @psalm-api
 */
final readonly class Effect
{
    /**
     * @param array<object> $events
     * @param array<Closure> $sideEffects
     */
    private function __construct(
        public EffectType $type,
        public array $events = [],
        public ?ActorRef $replyTo = null,
        public mixed $replyMsg = null,
        public array $sideEffects = [],
    ) {}

    /**
     * Persist one or more domain events and apply them to the actor state.
     *
     * Events are written to the `EventStore` before any side-effects run.
     * Chain `thenRun()` or `thenReply()` to execute callbacks after they are stored.
     */
    public static function persist(object ...$events): self
    {
        return new self(type: EffectType::Persist, events: $events);
    }

    /**
     * Acknowledge the command without persisting any events or side-effects.
     *
     * Use this for read-only commands or when a command is intentionally a no-op.
     * Chain `thenReply()` to still send a response back to the caller.
     */
    public static function none(): self
    {
        return new self(type: EffectType::None);
    }

    /**
     * Signal that the command was not handled by the current behavior.
     *
     * The message is routed to dead letters, matching the semantics of
     * `Behavior::unhandled()` in the stateless actor model.
     */
    public static function unhandled(): self
    {
        return new self(type: EffectType::Unhandled);
    }

    /**
     * Defer the current command by placing it back into the stash buffer.
     *
     * The stashed command is replayed automatically once the actor calls
     * `$ctx->unstashAll()`. Useful during recovery or state-machine transitions.
     */
    public static function stash(): self
    {
        return new self(type: EffectType::Stash);
    }

    /**
     * Stop the actor after the current effect (and any side-effects) complete.
     */
    public static function stop(): self
    {
        return new self(type: EffectType::Stop);
    }

    /**
     * Send a reply message directly to `$to` as the sole effect of handling a command.
     *
     * Use this when no state change is needed; for read commands that need a reply
     * after persisting events, prefer `Effect::persist(...)->thenReply(...)` instead.
     *
     * @param ActorRef $to      The actor to reply to (typically the command sender).
     * @param object   $message The reply message to send.
     */
    public static function reply(ActorRef $to, object $message): self
    {
        return new self(type: EffectType::Reply, replyTo: $to, replyMsg: $message);
    }

    /**
     * Attach a reply side-effect that runs after the events are persisted.
     *
     * The closure receives the final projected state and must return the reply object
     * to send to `$to`. Multiple `thenReply()` calls chain additional replies.
     *
     * @template TState of object
     * @template TReply of object
     * @param Closure(TState): TReply $fn receives final state, returns reply message
     */
    public function thenReply(ActorRef $to, Closure $fn): self
    {
        return new self(
            type: $this->type,
            events: $this->events,
            replyTo: $this->replyTo,
            replyMsg: $this->replyMsg,
            sideEffects: [
                ...$this->sideEffects,
                /** @psalm-suppress InvalidArgument $fn accepts any TState; runtime always invokes with the actor's state object */
                static function (object $state) use ($to, $fn): void {
                    /** @var TState $state */
                    $to->tell($fn($state));
                },
            ],
        );
    }

    /**
     * Attach an arbitrary side-effect closure that runs after the events are persisted.
     *
     * The closure receives the final projected state for inspection. Multiple `thenRun()`
     * calls chain additional side-effects; they execute in registration order.
     *
     * @template TState of object
     * @param Closure(TState): void $fn receives final state
     */
    public function thenRun(Closure $fn): self
    {
        return new self(
            type: $this->type,
            events: $this->events,
            replyTo: $this->replyTo,
            replyMsg: $this->replyMsg,
            sideEffects: [
                ...$this->sideEffects,
                /** @psalm-suppress InvalidArgument $fn accepts any TState; runtime always invokes with the actor's state object */
                static function (object $state) use ($fn): void {
                    /** @var TState $state */
                    $fn($state);
                },
            ],
        );
    }
}
