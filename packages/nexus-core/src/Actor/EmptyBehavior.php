<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Closure;
use Monadial\Nexus\Core\Lifecycle\Signal;
use Override;

/**
 * @psalm-api
 *
 * Behavior that silently ignores all messages (routes them to dead letters).
 * Useful as a default or placeholder behavior.
 *
 * @internal Implementation detail of {@see Behavior}. Not for direct use.
 *
 * @template T of object
 * @extends Behavior<T>
 */
final readonly class EmptyBehavior extends Behavior
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
