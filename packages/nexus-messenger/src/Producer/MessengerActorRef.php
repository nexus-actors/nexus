<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Producer;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Messenger\Ask\AskSupport;
use Monadial\Nexus\Messenger\Event\AskStarted;
use Monadial\Nexus\Messenger\Event\MessagePublished;
use Monadial\Nexus\Messenger\Exception\AskCapacityExceededException;
use Monadial\Nexus\Messenger\Exception\UnsupportedOperationException;
use Monadial\Nexus\Messenger\Stamp\CorrelationIdStamp;
use Monadial\Nexus\Messenger\Stamp\ReplyToStamp;
use Monadial\Nexus\Messenger\Stamp\SourceActorPathStamp;
use Monadial\Nexus\Messenger\Stamp\TraceContextStamp;
use Monadial\Nexus\Observability\NoopObservability;
use Monadial\Nexus\Observability\Observability;
use Monadial\Nexus\Observability\Trace\Span;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Observability\Trace\StatusCode;
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Duration;
use Override;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Throwable;

use function bin2hex;
use function random_bytes;

/**
 * ActorRef backed by a Symfony Messenger sender — the location-transparent
 * egress API. Actor code telling this ref is byte-identical to a local send;
 * the message leaves the process through the configured transport.
 *
 * When constructed without an {@see AskSupport}, ask() throws
 * {@see UnsupportedOperationException}. Passing an AskSupport instance (via
 * {@see \Monadial\Nexus\Messenger\MessengerBridge::producer()}) enables
 * broker request/reply: the ref stamps the outbound envelope with a
 * correlation ID and reply-to channel, registers a pending future slot, and
 * returns a Future that resolves when the reply consumer delivers the
 * matching reply.
 *
 * When observability is enabled, each tell() is wrapped in a Producer span
 * and counted; telemetry errors never break the send. When an event
 * dispatcher is provided, a MessagePublished event is dispatched after every
 * successful send.
 *
 * Example:
 * ```php
 * $ref = new MessengerActorRef($transport, 'orders-out');
 * $ref->tell(new OrderPlaced('A-42'));
 *
 * // With ask/reply:
 * $askSupport = MessengerBridge::askSupport($system, $channelFactory);
 * $ref = MessengerBridge::producer($transport, 'orders-out', askSupport: $askSupport);
 * $reply = $ref->ask(new Ping('hello'), Duration::seconds(5))->await(); // inside a fiber
 * ```
 *
 * @psalm-api
 *
 * @template T of object
 * @template-implements ActorRef<T>
 */
final readonly class MessengerActorRef implements ActorRef
{
    public function __construct(
        private SenderInterface $sender,
        private string $senderName,
        private ?ActorPath $sourcePath = null,
        private Observability $observability = new NoopObservability(),
        private ?EventDispatcherInterface $events = null,
        private ?AskSupport $askSupport = null,
    ) {
    }

    #[Override]
    public function tell(object $message): void
    {
        $envelope = new Envelope($message);

        if ($this->sourcePath !== null) {
            $envelope = $envelope->with(new SourceActorPathStamp((string) $this->sourcePath));
        }

        if (!$this->observability->isEnabled()) {
            $this->sender->send($envelope);
            $this->events?->dispatch(new MessagePublished($message, $this->senderName));

            return;
        }

        $span = $this->startSpan($message);
        $envelope = $this->injectTraceContext($envelope);

        try {
            $this->sender->send($envelope);
            $this->safely(fn(): mixed => $this->observability->meter()->counter(
                'nexus.messenger.messages.sent',
                '{message}',
                'Messages published to Symfony Messenger transports',
            )->add(1, ['nexus.message.type' => $message::class]));
        } catch (Throwable $e) {
            $this->recordError($span, $e);

            throw $e;
        } finally {
            $this->safely(static fn(): mixed => $span?->end());
        }

        $this->events?->dispatch(new MessagePublished($message, $this->senderName));
    }

    /**
     * @template R of object
     * @return Future<R>
     */
    #[Override]
    public function ask(object $message, Duration $timeout): Future
    {
        if ($this->askSupport === null) {
            throw new UnsupportedOperationException(
                'ask() is not supported on MessengerActorRef without AskSupport. '
                . 'Configure it via MessengerBridge::askSupport() and pass the result '
                . 'to MessengerBridge::producer() as the $askSupport argument.',
            );
        }

        $correlationId = bin2hex(random_bytes(16));
        $replyChannel = $this->askSupport->replyChannelName();

        try {
            $future = $this->askSupport->ask($message, $timeout, $correlationId);
        } catch (AskCapacityExceededException $e) {
            $this->safely(fn(): mixed => $this->observability->meter()->counter(
                'nexus.messenger.asks.capacity_rejected',
                '{ask}',
                'Ask requests rejected because the pending-ask registry was at capacity',
            )->add(1));

            throw $e;
        }

        $envelope = (new Envelope($message))
            ->with(new CorrelationIdStamp($correlationId))
            ->with(new ReplyToStamp($replyChannel));

        if ($this->sourcePath !== null) {
            $envelope = $envelope->with(new SourceActorPathStamp((string) $this->sourcePath));
        }

        if ($this->observability->isEnabled()) {
            $envelope = $this->injectTraceContext($envelope);
        }

        try {
            $this->sender->send($envelope);
        } catch (Throwable $e) {
            // Clean up the registered slot so the timeout never fires on a ghost entry.
            $this->askSupport->registry()->remove($correlationId);

            throw $e;
        }

        $this->safely(fn(): mixed => $this->observability->meter()->counter(
            'nexus.messenger.asks.sent',
            '{ask}',
            'Ask requests published to the transport',
        )->add(1));
        $this->safely(fn(): mixed => $this->events?->dispatch(new AskStarted($message, $correlationId)));

        /** @var Future<R> */
        return $future;
    }

    #[Override]
    public function path(): ActorPath
    {
        return ActorPath::root()->child('messenger')->child($this->senderName);
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

    private function startSpan(object $message): ?Span
    {
        try {
            return $this->observability->tracer()->startSpan(
                'messenger.send',
                SpanKind::Producer,
                [
                    'messaging.operation' => 'send',
                    'messaging.system' => 'symfony-messenger',
                    'nexus.message.type' => $message::class,
                    'nexus.messenger.sender' => $this->senderName,
                ],
            );
        } catch (Throwable) {
            return null;
        }
    }

    private function recordError(?Span $span, Throwable $e): void
    {
        $this->safely(static function () use ($span, $e): void {
            $span?->recordException($e);
            $span?->setStatus(StatusCode::Error, $e->getMessage());
        });
    }

    /**
     * @param callable(): mixed $fn
     */
    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable) {
            // Telemetry must never break message flow.
        }
    }
}
