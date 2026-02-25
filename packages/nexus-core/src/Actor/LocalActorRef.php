<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Closure;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Core\Exception\AskTimeoutException;
use Monadial\Nexus\Core\Exception\MailboxClosedException;
use Monadial\Nexus\Core\Exception\MailboxTimeoutException;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Core\Mailbox\Mailbox;
use Monadial\Nexus\Core\Mailbox\MailboxConfig;
use Monadial\Nexus\Core\Runtime\Runtime;
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
     * @param Runtime $runtime The runtime for creating temp mailboxes and scheduling
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
     * @template R of object
     * @param callable(ActorRef<R>): T $messageFactory
     * @return R
     */
    #[Override]
    public function ask(callable $messageFactory, Duration $timeout): object
    {
        $tempMailbox = $this->runtime->createMailbox(MailboxConfig::bounded(1));
        $tempPath = ActorPath::fromString('/temp/ask-' . spl_object_id($tempMailbox));

        /** @var ActorRef<R> $tempRef */
        $tempRef = new self($tempPath, $tempMailbox, static fn(): bool => true, $this->runtime);

        $message = $messageFactory($tempRef);
        $this->tell($message);

        $timer = $this->runtime->scheduleOnce($timeout, static function () use ($tempMailbox): void {
            $tempMailbox->close();
        });

        try {
            $envelope = $tempMailbox->dequeueBlocking($timeout);
            $timer->cancel();
            $tempMailbox->close();

            /** @var R */
            return $envelope->message;
        } catch (MailboxClosedException|MailboxTimeoutException) {
            throw new AskTimeoutException($this->path, $timeout);
        }
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
