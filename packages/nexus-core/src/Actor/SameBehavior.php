<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Closure;
use Monadial\Nexus\Core\Lifecycle\Signal;
use Override;

/**
 * @psalm-api
 *
 * Sentinel behavior returned from handlers to keep the current behavior unchanged.
 *
 * @internal Implementation detail of {@see Behavior::same()}. Not for direct use.
 *
 * @template T of object
 * @extends Behavior<T>
 */
final readonly class SameBehavior extends Behavior
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
