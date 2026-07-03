<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Producer;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Messenger\Event\MessagePublished;
use Monadial\Nexus\Messenger\Exception\UnsupportedOperationException;
use Monadial\Nexus\Messenger\Stamp\SourceActorPathStamp;
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

/**
 * ActorRef backed by a Symfony Messenger sender — the location-transparent
 * egress API. Actor code telling this ref is byte-identical to a local send;
 * the message leaves the process through the configured transport.
 *
 * ask() is not supported in v1: broker request/reply requires correlation
 * stamps and a reply transport.
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

    #[Override]
    public function ask(object $message, Duration $timeout): Future
    {
        throw new UnsupportedOperationException(
            'ask() is not supported on MessengerActorRef; broker request/reply is deferred beyond v1.',
        );
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
