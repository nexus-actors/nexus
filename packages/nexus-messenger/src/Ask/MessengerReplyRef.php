<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Ask;

use Closure;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Message\SystemMessage;
use Monadial\Nexus\Messenger\Event\ReplyPublished;
use Monadial\Nexus\Messenger\Exception\UnsupportedOperationException;
use Monadial\Nexus\Messenger\Stamp\CorrelationIdStamp;
use Monadial\Nexus\Messenger\Stamp\TraceContextStamp;
use Monadial\Nexus\Observability\NoopObservability;
use Monadial\Nexus\Observability\Observability;
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Duration;
use Override;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Throwable;

/**
 * ActorRef that publishes a reply message to a Symfony Messenger sender and fires a
 * one-shot process-ack callback on successful delivery.
 *
 * Created per ask-envelope by {@see \Monadial\Nexus\Messenger\Consumer\ReceiverActor}
 * and injected into the core Envelope as the senderRef, making it available to the
 * responder actor via {@see \Monadial\Nexus\Core\Actor\ActorContext::sender()}.
 * The responder uses {@see \Monadial\Nexus\Core\Actor\ActorContext::reply()} or
 * `$ctx->sender()->tell($reply)` to publish the reply.
 *
 * The ack callback is fired exactly once after a successful send. The callback's
 * internal boolean guard (set up by ReceiverActor) prevents double-acking if tell()
 * is called more than once or if the pending entry was already expired.
 *
 * **At-least-once publish:** calling tell() more than once publishes additional reply
 * envelopes to the transport on every call (at-least-once delivery). Only the broker ack
 * is one-shot — the asker's first-reply-wins registry drops any extras on the consumer side.
 *
 * If `send()` throws, the callback is NOT fired, preserving the process-ack guarantee:
 * the broker message stays un-acked and will be redelivered or expired by ReceiverActor.
 *
 * ask() is not supported — this ref is reply-only.
 *
 * Example (inside a responder actor):
 * ```php
 * // The actor replies via ActorContext::reply(), which delegates to this ref's tell():
 * $ctx->reply(new Pong('ok'));
 * // → publishes reply with CorrelationIdStamp to the configured reply sender
 * // → acks the original broker request envelope
 * ```
 *
 * @psalm-api
 *
 * @template T of object
 * @template-implements ActorRef<T>
 */
final readonly class MessengerReplyRef implements ActorRef
{
    /**
     * @param Closure(): void $ackCallback fired after a successful reply publish; self-disarms via internal bool guard in ReceiverActor
     */
    public function __construct(
        private SenderInterface $sender,
        private string $correlationId,
        private Closure $ackCallback,
        private Observability $observability = new NoopObservability(),
        private ?EventDispatcherInterface $events = null,
    ) {
    }

    /**
     * Publish the reply to the configured sender, ack the original broker envelope,
     * and dispatch a ReplyPublished event.
     *
     * The CorrelationIdStamp is always attached. When observability is enabled, a
     * TraceContextStamp is also injected for distributed tracing continuity.
     *
     * If `send()` throws, the ack callback is NOT fired (process-ack guarantee: we
     * only ack after the reply is durably handed off to the transport).
     *
     * @param T|SystemMessage $message
     */
    #[Override]
    public function tell(object $message): void
    {
        $envelope = (new Envelope($message))->with(new CorrelationIdStamp($this->correlationId));

        if ($this->observability->isEnabled()) {
            $envelope = $this->injectTraceContext($envelope);
        }

        $this->sender->send($envelope);

        ($this->ackCallback)();

        $this->swallow(fn(): mixed => $this->observability->meter()->counter(
            'nexus.messenger.replies.sent',
            '{message}',
            'Reply messages published back to the requester transport',
        )->add(1, ['nexus.message.type' => $message::class]));
        $this->swallow(fn(): mixed => $this->events?->dispatch(new ReplyPublished($message, $this->correlationId)));
    }

    /**
     * @template R of object
     * @return Future<R>
     */
    #[Override]
    public function ask(object $message, Duration $timeout): Future
    {
        throw new UnsupportedOperationException(
            'ask() is not supported on MessengerReplyRef; it is a reply-only reference.',
        );
    }

    #[Override]
    public function path(): ActorPath
    {
        $safe = preg_replace('/[^a-zA-Z0-9_.\\-]/', '-', $this->correlationId) ?? '';

        return ActorPath::fromString('/messenger/reply/' . ($safe !== '' ? $safe : 'x'));
    }

    #[Override]
    public function isAlive(): bool
    {
        return true;
    }

    private function injectTraceContext(Envelope $envelope): Envelope
    {
        try {
            $carrier = [];
            $this->observability->propagator()->inject($this->observability->currentContext(), $carrier);

            return $envelope->with(new TraceContextStamp($carrier));
        } catch (Throwable) {
            return $envelope;
        }
    }

    /**
     * @param callable(): mixed $fn
     */
    private function swallow(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable) {
            // Telemetry must never break message flow.
        }
    }
}
