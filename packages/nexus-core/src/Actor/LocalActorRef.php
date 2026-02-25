<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Closure;
use Monadial\Nexus\Core\Async\Future;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Core\Exception\AskTimeoutException;
use Monadial\Nexus\Core\Exception\MailboxClosedException;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Core\Mailbox\Mailbox;
use Monadial\Nexus\Core\Runtime\Runtime;
use NoDiscard;
use Override;

/**
 * @psalm-api
 *
 * Local (in-process) actor reference that delivers messages via a mailbox.
 *
 * @template T of object
 * @implements ActorRef<T>
 */
final readonly class LocalActorRef implements ActorRef
{
    /**
     * @param ActorPath $path The actor's path in the hierarchy
     * @param Mailbox $mailbox The actor's mailbox for message delivery
     * @param Closure(): bool $aliveChecker Closure that checks whether the actor is alive
     * @param Runtime $runtime Runtime for creating FutureSlots
     */
    public function __construct(
        private ActorPath $path,
        private Mailbox $mailbox,
        private Closure $aliveChecker,
        private Runtime $runtime,
    ) {}

    /** @param T $message */
    #[Override]
    public function tell(object $message): void
    {
        try {
            $_ = $this->mailbox->enqueue(Envelope::of($message, ActorPath::root(), $this->path));
        } catch (MailboxClosedException) {
            // fire-and-forget: silently drop messages to closed mailboxes
        }
    }

    /**
     * Deliver a pre-formed envelope directly to the mailbox.
     * Used by cluster transport to preserve sender path from remote workers.
     */
    public function enqueueEnvelope(Envelope $envelope): void
    {
        try {
            $_ = $this->mailbox->enqueue($envelope);
        } catch (MailboxClosedException) {
            // fire-and-forget: silently drop messages to closed mailboxes
        }
    }

    /**
     * @param T $message
     * @return Future<object>
     * @throws AskTimeoutException
     */
    #[Override]
    #[NoDiscard]
    public function ask(object $message, Duration $timeout): Future
    {
        $slot = $this->runtime->createFutureSlot($timeout);
        $futureRefPath = ActorPath::fromString('/temp/ask-' . spl_object_id($slot));
        $futureRef = new FutureRef($futureRefPath, $slot);

        $envelope = new Envelope($message, $futureRefPath, $this->path, $futureRef);

        try {
            $_ = $this->mailbox->enqueue($envelope);
        } catch (MailboxClosedException) {
            $slot->fail(new AskTimeoutException($this->path, $timeout));
        }

        return new Future($slot);
    }

    #[Override]
    public function path(): ActorPath
    {
        return $this->path;
    }

    #[Override]
    public function isAlive(): bool
    {
        return ($this->aliveChecker)();
    }
}
