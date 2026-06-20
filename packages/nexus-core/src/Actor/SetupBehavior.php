<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Closure;
use Monadial\Nexus\Core\Lifecycle\Signal;
use Override;

/**
 * @psalm-api
 *
 * Deferred behavior factory. The factory is called once when the actor starts,
 * receiving the ActorContext, and returns the actual runtime Behavior.
 *
 * @internal Implementation detail of {@see Behavior::setup()}. Not for direct use.
 *
 * @template T of object
 * @extends Behavior<T>
 */
final readonly class SetupBehavior extends Behavior
{
    /**
     * @param Closure(ActorContext<T>): Behavior<T> $factory
     * @param Closure(ActorContext<T>, Signal): Behavior<T>|null $signalHandler
     */
    public function __construct(public Closure $factory, private ?Closure $signalHandler = null) {}

    #[Override]
    public function signalHandler(): ?Closure
    {
        return $this->signalHandler;
    }

    #[Override]
    public function onSignal(Closure $handler): static
    {
        return new self($this->factory, $handler);
    }
}
