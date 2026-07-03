<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Producer;

use Monadial\Nexus\Messenger\Event\MessagePublished;
use Monadial\Nexus\Messenger\Stamp\TraceContextStamp;
use Monadial\Nexus\Observability\NoopObservability;
use Monadial\Nexus\Observability\Observability;
use Monadial\Nexus\Observability\Trace\Span;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Observability\Trace\StatusCode;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Throwable;

/**
 * Explicit egress service for code that wants to be deliberate that a message
 * leaves the actor system. Same underlying sender as MessengerActorRef —
 * choose this API when "this goes to a broker" should be visible at the call
 * site.
 *
 * When observability is enabled, each publish() is wrapped in a Producer span
 * and counted; telemetry errors never break the send. When an event
 * dispatcher is provided, a MessagePublished event is dispatched after every
 * successful send.
 *
 * @psalm-api
 */
final readonly class MessengerGateway
{
    private const string SENDER_NAME = 'gateway';

    public function __construct(
        private SenderInterface $sender,
        private Observability $observability = new NoopObservability(),
        private ?EventDispatcherInterface $events = null,
    ) {
    }

    /**
     * @param list<StampInterface> $stamps
     */
    public function publish(object $message, array $stamps = []): void
    {
        $envelope = new Envelope($message, $stamps);

        if (!$this->observability->isEnabled()) {
            $this->sender->send($envelope);
            $this->events?->dispatch(new MessagePublished($message, self::SENDER_NAME));

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

        $this->events?->dispatch(new MessagePublished($message, self::SENDER_NAME));
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
                    'nexus.messenger.sender' => self::SENDER_NAME,
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
