<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Closure;
use Monadial\Nexus\Core\Exception\AskTimeoutException;
use Monadial\Nexus\Core\Exception\AskUndeliverableException;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Core\Message\SystemMessage;
use Monadial\Nexus\Observability\Observability;
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Exception\MailboxClosedException;
use Monadial\Nexus\Runtime\Mailbox\EnqueueResult;
use Monadial\Nexus\Runtime\Mailbox\Mailbox;
use Monadial\Nexus\Runtime\Runtime\Runtime;
use NoDiscard;
use Override;

/**
 * @psalm-api
 *
 * Local (in-process) actor reference that delivers messages via a mailbox.
 *
 * @internal Implementation detail of {@see ActorRef}. Not for direct use.
 *
 * @template T of object
 * @implements ActorRef<T>
 * @implements BackpressureCapable<T>
 */
final readonly class LocalActorRef implements ActorRef, BackpressureCapable
{
    /**
     * @param ActorPath $path The actor's path in the hierarchy
     * @param Mailbox<Envelope> $mailbox The actor's mailbox for message delivery
     * @param Closure(): bool $aliveChecker Closure that checks whether the actor is alive
     * @param Runtime $runtime Runtime for creating FutureSlots
     * @param Observability $observability Provider used to inject trace context into outgoing envelopes.
     */
    public function __construct(
        private ActorPath $path,
        private Mailbox $mailbox,
        private Closure $aliveChecker,
        private Runtime $runtime,
        private Observability $observability,
    ) {}

    /** @param T|SystemMessage $message */
    #[Override]
    public function tell(object $message): void
    {
        $_ = $this->offer($message);
    }

    /**
     * @param T|SystemMessage $message
     */
    #[Override]
    #[NoDiscard]
    public function offer(object $message): EnqueueResult
    {
        try {
            return $this->mailbox->enqueue($this->envelopeFor($message, ActorPath::root()));
        } catch (MailboxClosedException) {
            return EnqueueResult::Dropped;
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
     * Deliver a pre-formed envelope directly to the mailbox, returning the enqueue result.
     *
     * Mirrors {@see enqueueEnvelope()} but exposes the result so callers can react to
     * backpressure or a dropped delivery without a separate offer() that strips the
     * envelope context (senderRef, correlationId, etc.). Used by the Messenger bridge's
     * ask-aware receiver path to support process-ack with backpressure visibility.
     *
     * @return EnqueueResult Accepted on success, Dropped when the mailbox is closed
     */
    #[NoDiscard]
    public function offerEnvelope(Envelope $envelope): EnqueueResult
    {
        try {
            return $this->mailbox->enqueue($envelope);
        } catch (MailboxClosedException) {
            return EnqueueResult::Dropped;
        }
    }

    /**
     * @template R of object
     * @param T $message
     * @return Future<R>
     * @throws AskTimeoutException
     */
    #[Override]
    #[NoDiscard]
    public function ask(object $message, Duration $timeout): Future
    {
        $slot = $this->runtime->createFutureSlot();
        $futureRefPath = ActorPath::fromString('/temp/ask-' . spl_object_id($slot));
        $futureRef = new FutureRef($futureRefPath, $slot);
        $targetPath = $this->path;

        $this->runtime->scheduleOnce($timeout, static function () use ($slot, $targetPath, $timeout): void {
            $slot->fail(new AskTimeoutException($targetPath, $timeout));
        });

        $envelope = $this->envelopeFor($message, $futureRefPath)->withSenderRef($futureRef);

        try {
            $result = $this->mailbox->enqueue($envelope);

            // The message never entered the mailbox: fail now instead of
            // making the caller wait out the full ask timeout.

            if ($result !== EnqueueResult::Accepted) {
                $slot->fail(new AskUndeliverableException($this->path, $result));
            }
        } catch (MailboxClosedException) {
            $slot->fail(new AskTimeoutException($this->path, $timeout));
        }

        /** @var Future<R> */
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

    private function envelopeFor(object $message, ActorPath $sender): Envelope
    {
        $envelope = Envelope::of($message, $sender, $this->path);

        if (!$this->observability->isEnabled()) {
            return $envelope;
        }

        $carrier = [];
        $this->observability->propagator()->inject($this->observability->currentContext(), $carrier);

        return $carrier === []
            ? $envelope
            : $envelope->withMetadata($carrier);
    }
}
