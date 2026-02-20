<?php
declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Closure;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Core\Exception\MailboxClosedException;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Core\Mailbox\Mailbox;
use Override;
use RuntimeException;

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
     */
    public function __construct(private ActorPath $path, private Mailbox $mailbox, private Closure $aliveChecker)
    {
    }

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
     * @template R of object
     * @param callable(ActorRef<R>): T $messageFactory
     * @return R
     */
    #[Override]
    public function ask(callable $messageFactory, Duration $timeout): object
    {
        // Will be wired up with ActorSystem in Task 9
        throw new RuntimeException('ask() requires ActorSystem');
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
