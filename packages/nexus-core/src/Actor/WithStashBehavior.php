<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Closure;
use Monadial\Nexus\Core\Lifecycle\Signal;
use Override;

/**
 * @psalm-api
 *
 * Wrapper behavior that provides a StashBuffer to its factory before resolving
 * the runtime behavior. The capacity limits how many messages can be stashed.
 *
 * @template T of object
 * @extends Behavior<T>
 */
final readonly class WithStashBehavior extends Behavior
{
    /**
     * @psalm-suppress UndefinedDocblockClass StashBuffer will be created in a future task
     * @param Closure(StashBuffer): Behavior<T> $factory
     * @param Closure(ActorContext<T>, Signal): Behavior<T>|null $signalHandler
     */
    public function __construct(public Closure $factory, public int $capacity, private ?Closure $signalHandler = null) {}

    #[Override]
    public function signalHandler(): ?Closure
    {
        return $this->signalHandler;
    }

    #[Override]
    public function onSignal(Closure $handler): static
    {
        return new self($this->factory, $this->capacity, $handler);
    }
}
