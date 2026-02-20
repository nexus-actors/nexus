<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\EventSourced;

use Closure;
use Monadial\Nexus\Core\Actor\ActorRef;

final class Effect
{
    /**
     * @param array<object> $events
     * @param array<Closure> $sideEffects
     */
    private function __construct(
        public readonly EffectType $type,
        public readonly array $events = [],
        public readonly ?ActorRef $replyTo = null,
        public readonly mixed $replyMsg = null,
        public readonly array $sideEffects = [],
    ) {}

    public static function persist(object ...$events): self
    {
        return new self(
            type: EffectType::Persist,
            events: $events,
        );
    }

    public static function none(): self
    {
        return new self(type: EffectType::None);
    }

    public static function unhandled(): self
    {
        return new self(type: EffectType::Unhandled);
    }

    public static function stash(): self
    {
        return new self(type: EffectType::Stash);
    }

    public static function stop(): self
    {
        return new self(type: EffectType::Stop);
    }

    public static function reply(ActorRef $to, object $message): self
    {
        return new self(
            type: EffectType::Reply,
            replyTo: $to,
            replyMsg: $message,
        );
    }

    /**
     * @param Closure(object): object $fn receives final state, returns reply message
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
            events: $this->events,
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
