<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Closure;
use Monadial\Nexus\Core\Lifecycle\Signal;
use Monadial\Nexus\Core\Supervision\SupervisionStrategy;
use Override;

/**
 * @psalm-api
 *
 * Wrapper behavior that installs a custom SupervisionStrategy for its inner behavior.
 * Exceptions thrown by the inner behavior's handler are decided by this strategy first.
 *
 * @template T of object
 * @extends Behavior<T>
 */
final readonly class SupervisedBehavior extends Behavior
{
    /**
     * @param Behavior<T> $inner
     * @param Closure(ActorContext<T>, Signal): Behavior<T>|null $signalHandler
     */
    public function __construct(
        public Behavior $inner,
        public SupervisionStrategy $strategy,
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
        return new self($this->inner, $this->strategy, $handler);
    }
}
