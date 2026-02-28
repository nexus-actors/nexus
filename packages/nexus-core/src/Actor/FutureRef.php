<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Async\FutureSlot;
use Monadial\Nexus\Runtime\Duration;
use NoDiscard;
use Override;
use RuntimeException;

/**
 * @psalm-api
 *
 * Lightweight ActorRef whose tell() resolves a FutureSlot.
 *
 * Created internally by ask(). Not intended for direct use.
 *
 * @template T of object
 * @implements ActorRef<T>
 */
final readonly class FutureRef implements ActorRef
{
    public function __construct(private ActorPath $path, private FutureSlot $slot) {}

    /** @param T $message */
    #[Override]
    public function tell(object $message): void
    {
        $this->slot->resolve($message);
    }

    #[Override]
    public function enqueueEnvelope(Envelope $envelope): void
    {
        $this->slot->resolve($envelope->message);
    }

    /**
     * @template R of object
     * @return Future<R>
     */
    #[Override]
    #[NoDiscard]
    public function ask(object $message, Duration $timeout): Future
    {
        throw new RuntimeException('Cannot ask() a FutureRef');
    }

    #[Override]
    public function path(): ActorPath
    {
        return $this->path;
    }

    #[Override]
    public function isAlive(): bool
    {
        return !$this->slot->isResolved();
    }
}
