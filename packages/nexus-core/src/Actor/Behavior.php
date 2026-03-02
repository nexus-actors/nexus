<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Closure;
use Monadial\Nexus\Core\Lifecycle\Signal;
use Monadial\Nexus\Core\Supervision\SupervisionStrategy;

/**
 * @psalm-api
 *
 * Immutable behavior definition for actors.
 *
 * Template parameter T represents the message protocol the actor handles.
 *
 * @template T of object
 */
final readonly class Behavior
{
    private function __construct(
        private BehaviorTag $tag,
        private ?Closure $handler,
        private ?Closure $signalHandler,
        private mixed $initialState,
    ) {}

    /**
     * @template U of object
     * @param Closure(ActorContext<U>, U): Behavior<U> $handler
     * @return Behavior<U>
     */
    public static function receive(Closure $handler): self
    {
        /** @var Behavior<U> */
        return new self(BehaviorTag::Receive, $handler, null, null);
    }

    /**
     * @template U of object
     * @template S
     * @param S $initialState
     * @param Closure(ActorContext<U>, U, S): BehaviorWithState<U, S> $handler
     * @return Behavior<U>
     */
    public static function withState(mixed $initialState, Closure $handler): self
    {
        /** @var Behavior<U> */
        return new self(BehaviorTag::WithState, $handler, null, $initialState);
    }

    /**
     * @template U of object
     * @param Closure(ActorContext<U>): Behavior<U> $factory
     * @return Behavior<U>
     */
    public static function setup(Closure $factory): self
    {
        /** @var Behavior<U> */
        return new self(BehaviorTag::Setup, $factory, null, null);
    }

    /**
     * @template U of object
     * @param Closure(TimerScheduler): Behavior<U> $factory
     * @return Behavior<U>
     * @psalm-suppress UndefinedDocblockClass TimerScheduler will be created in a future task
     * @psalm-suppress UnusedParam $factory is stored for resolution by ActorCell
     */
    public static function withTimers(Closure $factory): self
    {
        /** @var Behavior<U> */
        return new self(BehaviorTag::WithTimers, $factory, null, null);
    }

    /**
     * @template U of object
     * @param Closure(StashBuffer): Behavior<U> $factory
     * @return Behavior<U>
     * @psalm-suppress UndefinedDocblockClass StashBuffer will be created in a future task
     * @psalm-suppress UnusedParam $capacity and $factory are stored for resolution by ActorCell
     */
    public static function withStash(int $capacity, Closure $factory): self
    {
        /** @var Behavior<U> */
        return new self(BehaviorTag::WithStash, $factory, null, $capacity);
    }

    /**
     * @template U of object
     * @param Behavior<U> $inner
     * @return Behavior<U>
     */
    public static function supervise(self $inner, SupervisionStrategy $strategy): self
    {
        $provider = static fn(): self => $inner;

        /** @var Behavior<U> */
        return new self(BehaviorTag::Supervised, $provider, null, $strategy);
    }

    /**
     * @internal Used by StashBuffer to create a replay behavior.
     *
     * @template U of object
     * @param list<\Monadial\Nexus\Core\Mailbox\Envelope> $envelopes
     * @param Behavior<U> $target
     * @return Behavior<U>
     */
    public static function unstashAllReplay(array $envelopes, self $target): self
    {
        $provider = static fn(): array => [
            'envelopes' => $envelopes,
            'target' => $target,
        ];

        /** @var Behavior<U> */
        return new self(BehaviorTag::UnstashAll, $provider, null, null);
    }

    /**
     * @return Behavior<T>
     */
    public static function same(): self
    {
        /** @var Behavior<T> */
        return new self(BehaviorTag::Same, null, null, null);
    }

    /**
     * @return Behavior<T>
     */
    public static function stopped(): self
    {
        /** @var Behavior<T> */
        return new self(BehaviorTag::Stopped, null, null, null);
    }

    /**
     * @return Behavior<T>
     */
    public static function unhandled(): self
    {
        /** @var Behavior<T> */
        return new self(BehaviorTag::Unhandled, null, null, null);
    }

    /**
     * @return Behavior<T>
     */
    public static function empty(): self
    {
        /** @var Behavior<T> */
        return new self(BehaviorTag::Empty, null, null, null);
    }

    /**
     * @param Closure(ActorContext<T>, Signal): Behavior<T> $handler
     * @return Behavior<T>
     */
    public function onSignal(Closure $handler): self
    {
        /** @var Behavior<T> */
        return new self($this->tag, $this->handler, $handler, $this->initialState);
    }

    public function tag(): BehaviorTag
    {
        return $this->tag;
    }

    public function isSame(): bool
    {
        return $this->tag === BehaviorTag::Same;
    }

    public function isStopped(): bool
    {
        return $this->tag === BehaviorTag::Stopped;
    }

    public function isUnhandled(): bool
    {
        return $this->tag === BehaviorTag::Unhandled;
    }

    public function handler(): ?Closure
    {
        return $this->handler;
    }

    public function signalHandler(): ?Closure
    {
        return $this->signalHandler;
    }

    public function initialState(): mixed
    {
        return $this->initialState;
    }
}
