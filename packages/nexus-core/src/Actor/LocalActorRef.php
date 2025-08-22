<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Core\Exception\MailboxClosedException;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Core\Mailbox\Mailbox;

/**
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
     * @param \Closure(): bool $aliveChecker Closure that checks whether the actor is alive
     */
    public function __construct(
        private ActorPath $path,
        private Mailbox $mailbox,
        private \Closure $aliveChecker,
    ) {}

    /** @param T $message */
    public function tell(object $message): void
    {
        try {
            $_ = $this->mailbox->enqueue(Envelope::of($message, ActorPath::root(), $this->path));
        } catch (MailboxClosedException) {
            // fire-and-forget: silently drop messages to closed mailboxes
        }
    }

    /**
     * @template R of object
     * @param callable(ActorRef<R>): T $messageFactory
     * @return R
     */
    public function ask(callable $messageFactory, Duration $timeout): object
    {
        // Will be wired up with ActorSystem in Task 9
        throw new \RuntimeException('ask() requires ActorSystem');
    }

    public function path(): ActorPath
    {
        return $this->path;
    }

    public function isAlive(): bool
    {
        return ($this->aliveChecker)();
    }
}
