<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Closure;
use Monadial\Nexus\Core\Lifecycle\Signal;
use Override;

/**
 * @psalm-api
 *
 * Sentinel behavior returned from handlers to stop the actor.
 *
 * @template T of object
 * @extends Behavior<T>
 */
final readonly class StoppedBehavior extends Behavior
{
    /** @param Closure(ActorContext<T>, Signal): Behavior<T>|null $signalHandler */
    public function __construct(private ?Closure $signalHandler = null) {}

    #[Override]
    public function signalHandler(): ?Closure
    {
        return $this->signalHandler;
    }

    #[Override]
    public function onSignal(Closure $handler): static
    {
        return new self($handler);
    }
}
