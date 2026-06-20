<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Closure;
use Monadial\Nexus\Core\Lifecycle\Signal;
use Override;

/**
 * @psalm-api
 *
 * Stateless message-handling behavior. The handler receives a context and a message
 * and returns the next Behavior.
 *
 * @internal Implementation detail of {@see Behavior::receive()}. Not for direct use.
 *
 * @template T of object
 * @extends Behavior<T>
 */
final readonly class ReceiveBehavior extends Behavior
{
    /**
     * @param Closure(ActorContext<T>, T): Behavior<T> $handler
     * @param Closure(ActorContext<T>, Signal): Behavior<T>|null $signalHandler
     */
    public function __construct(public Closure $handler, private ?Closure $signalHandler = null) {}

    #[Override]
    public function signalHandler(): ?Closure
    {
        return $this->signalHandler;
    }

    #[Override]
    public function onSignal(Closure $handler): static
    {
        return new self($this->handler, $handler);
    }
}
