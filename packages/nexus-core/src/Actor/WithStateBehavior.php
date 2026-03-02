<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Closure;
use Monadial\Nexus\Core\Lifecycle\Signal;
use Override;

/**
 * @psalm-api
 *
 * Stateful message-handling behavior. The handler receives a context, a message,
 * and the current state, and returns a BehaviorWithState describing the next state.
 *
 * @template T of object
 * @template S
 * @extends Behavior<T>
 */
final readonly class WithStateBehavior extends Behavior
{
    /**
     * @param Closure(ActorContext<T>, T, S): BehaviorWithState<T, S> $handler
     * @param S $initialState
     * @param Closure(ActorContext<T>, Signal): Behavior<T>|null $signalHandler
     */
    public function __construct(
        public Closure $handler,
        public mixed $initialState,
        private ?Closure $signalHandler = null,
    ) {}

    #[Override]
    public function signalHandler(): ?Closure
    {
        return $this->signalHandler;
    }

    #[Override]
    public function onSignal(Closure $handler): static
    {
        return new self($this->handler, $this->initialState, $handler);
    }
}
