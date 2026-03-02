<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Closure;
use Monadial\Nexus\Core\Lifecycle\Signal;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Override;

/**
 * @internal Used by DefaultStashBuffer to replay stashed messages.
 *
 * Carries a list of stashed envelopes and the target behavior to switch to
 * before replaying them. ActorCell detects this type and calls handleUnstashAll().
 *
 * @template T of object
 * @extends Behavior<T>
 */
final readonly class UnstashAllBehavior extends Behavior
{
    /**
     * @param list<Envelope> $envelopes
     * @param Behavior<T> $target
     * @param Closure(ActorContext<T>, Signal): Behavior<T>|null $signalHandler
     */
    public function __construct(
        public array $envelopes,
        public Behavior $target,
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
        return new self($this->envelopes, $this->target, $handler);
    }
}
