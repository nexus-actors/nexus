<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\State;

use Closure;
use Monadial\Nexus\Core\Actor\ActorRef;

/** @psalm-api */
final readonly class DurableEffect
{
    /**
     * @param array<Closure> $sideEffects
     */
    private function __construct(
        public DurableEffectType $type,
        public ?object $state = null,
        public ?ActorRef $replyTo = null,
        public mixed $replyMsg = null,
        public array $sideEffects = [],
    ) {}

    public static function persist(object $newState): self
    {
        return new self(type: DurableEffectType::Persist, state: $newState);
    }

    public static function none(): self
    {
        return new self(type: DurableEffectType::None);
    }

    /**
     * Signal that the command was not handled by the current behavior.
     *
     * The command is routed to dead letters, matching the semantics of
     * `Behavior::unhandled()` in the stateless actor model, and the state is
     * left unchanged.
     */
    public static function unhandled(): self
    {
        return new self(type: DurableEffectType::Unhandled);
    }

    public static function stash(): self
    {
        return new self(type: DurableEffectType::Stash);
    }

    public static function stop(): self
    {
        return new self(type: DurableEffectType::Stop);
    }

    public static function reply(ActorRef $to, object $message): self
    {
        return new self(type: DurableEffectType::Reply, replyTo: $to, replyMsg: $message);
    }

    /**
     * @param Closure(object): object $fn receives final state, returns reply message
     */
    public function thenReply(ActorRef $to, Closure $fn): self
    {
        return new self(
            type: $this->type,
            state: $this->state,
            replyTo: $this->replyTo,
            replyMsg: $this->replyMsg,
            sideEffects: [
                ...$this->sideEffects,
                static function (object $state) use ($to, $fn): void {
                    $to->tell($fn($state));
                },
            ],
        );
    }

    /**
     * @param Closure(object): void $fn receives final state
     */
    public function thenRun(Closure $fn): self
    {
        return new self(
            type: $this->type,
            state: $this->state,
            replyTo: $this->replyTo,
            replyMsg: $this->replyMsg,
            sideEffects: [
                ...$this->sideEffects,
                static function (object $state) use ($fn): void {
                    $fn($state);
                },
            ],
        );
    }
}
