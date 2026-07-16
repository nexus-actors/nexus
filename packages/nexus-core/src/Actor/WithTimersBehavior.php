<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Closure;
use Monadial\Nexus\Core\Lifecycle\Signal;
use Override;

/**
 * @psalm-api
 *
 * Wrapper behavior that provides a TimerScheduler to its factory before resolving
 * the runtime behavior.
 *
 * @internal Implementation detail of {@see ActorContext::scheduleOnce()}. Not for direct use.
 *
 * @template T of object
 * @extends Behavior<T>
 */
final readonly class WithTimersBehavior extends Behavior
{
    /**
     * @param Closure(TimerScheduler): Behavior<T> $factory
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
