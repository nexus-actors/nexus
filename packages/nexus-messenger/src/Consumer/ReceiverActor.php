<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Consumer;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\BackpressureCapable;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\LocalActorRef;
use Monadial\Nexus\Core\Mailbox\Envelope as CoreEnvelope;
use Monadial\Nexus\Messenger\Ask\MessengerReplyRef;
use Monadial\Nexus\Messenger\Ask\ReplySenderLocator;
use Monadial\Nexus\Messenger\Event\MessageConsumed;
use Monadial\Nexus\Messenger\Event\MessageDeadLettered;
use Monadial\Nexus\Messenger\Event\MessageRejected;
use Monadial\Nexus\Messenger\Lifecycle\MessagesProcessed;
use Monadial\Nexus\Messenger\Routing\MessageRouter;
use Monadial\Nexus\Messenger\Stamp\CorrelationIdStamp;
use Monadial\Nexus\Messenger\Stamp\ReplyToStamp;
use Monadial\Nexus\Messenger\Stamp\TraceContextStamp;
use Monadial\Nexus\Observability\NoopObservability;
use Monadial\Nexus\Observability\Observability;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Runtime\Mailbox\EnqueueResult;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Throwable;

/**
 * Supervised poll → route → ack loop over one Messenger receiver.
 *
 * Each Poll tick drains the envelopes the receiver has available now:
 * routed messages are offered to the target's mailbox and acked only when
 * accepted; a Backpressured/Dropped enqueue stops the tick without acking,
 * so the broker redelivers (at-least-once). Unroutable messages are rejected
 * or forwarded to dead letters per ReceiverActorConfig. When the receiver is
 * idle the next poll is scheduled after pollInterval; a busy tick re-polls
 * immediately.
 *
 * **Ask / process-ack path:** when a ReplySenderLocator is configured and an
 * envelope carries both a CorrelationIdStamp and a ReplyToStamp, the actor
 * delivers the message with a MessengerReplyRef as the senderRef so the
 * responder actor can call `$ctx->reply($msg)`. The broker envelope is NOT
 * acked until the responder publishes the reply. Pending asks are tracked in
 * an in-actor map and rejected for redelivery if not answered within
 * ReceiverActorConfig::$askPendingTimeout (default 30 s).
 *
 * Every drained envelope is traced with a Consumer span and counted via the
 * actor context's tracer/meter (no-ops when observability is disabled). When
 * an event dispatcher is provided, MessageConsumed / MessageRejected /
 * MessageDeadLettered events are dispatched per outcome.
 *
 * Spawn one per receiver, under supervision for broker-blip restarts:
 * ```php
 * $system->spawn(
 *     Props::fromBehavior(ReceiverActor::create($transport, $router)),
 *     'orders-receiver',
 * );
 * ```
 *
 * @psalm-api
 */
final readonly class ReceiverActor
{
    private function __construct()
    {
    }

    /**
     * @param ActorRef<object>|null $deadLetters required when the config policy is UnroutablePolicy::DeadLetters
     * @param ActorRef<object>|null $processedListener receives MessagesProcessed reports (e.g. the LifecycleWatchdog)
     * @return Behavior<object>
     * @psalm-suppress InvalidArgument Psalm cannot infer U through nested setup→receive generic closures
     */
    public static function create(
        ReceiverInterface $receiver,
        MessageRouter $router,
        ?ReceiverActorConfig $config = null,
        ?ActorRef $deadLetters = null,
        ?ActorRef $processedListener = null,
        ?EventDispatcherInterface $events = null,
        ?Observability $observability = null,
        ?ReplySenderLocator $replySenders = null,
    ): Behavior {
        $config ??= ReceiverActorConfig::default();

        return Behavior::setup(
            static function (ActorContext $ctx) use ($receiver, $router, $config, $deadLetters, $processedListener, $events, $observability, $replySenders): Behavior {
                $ctx->self()->tell(new Poll());

                /** @var array<string, array{deadline: float, disarm: \Closure(): void, envelope: Envelope}> */
                $pendingAsks = [];
                $warnedNoLocator = false;

                return Behavior::receive(
                    static function (ActorContext $ctx, object $message) use ($receiver, $router, $config, $deadLetters, $processedListener, $events, $observability, $replySenders, &$pendingAsks, &$warnedNoLocator): Behavior {
                        if (!$message instanceof Poll) {
                            return Behavior::unhandled();
                        }

                        self::expirePendingAsks($ctx, $receiver, $pendingAsks);

                        [$processed, $backpressured] = self::drainOnce(
                            $ctx,
                            $receiver,
                            $router,
                            $config,
                            $deadLetters,
                            $events,
                            $observability,
                            $replySenders,
                            $pendingAsks,
                            $warnedNoLocator,
                        );

                        if ($processed > 0 && $processedListener !== null) {
                            $processedListener->tell(new MessagesProcessed($processed));
                        }

                        if ($processed > 0 && !$backpressured) {
                            $ctx->self()->tell(new Poll());
                        } else {
                            $ctx->scheduleOnce($config->pollInterval, new Poll());
                        }

                        return Behavior::same();
                    },
                );
            },
        );
    }

    /**
     * @param ActorContext<object> $ctx
     * @param ActorRef<object>|null $deadLetters
     * @param array<string, array{deadline: float, disarm: \Closure(): void, envelope: Envelope}> $pendingAsks
     * @return array{0: int, 1: bool} messages counted this tick, whether the tick stopped on backpressure
     */
    private static function drainOnce(
        ActorContext $ctx,
        ReceiverInterface $receiver,
        MessageRouter $router,
        ReceiverActorConfig $config,
        ?ActorRef $deadLetters,
        ?EventDispatcherInterface $events,
        ?Observability $observability,
        ?ReplySenderLocator $replySenders,
        array &$pendingAsks,
        bool &$warnedNoLocator,
    ): array {
        $processed = 0;

        foreach ($receiver->get() as $envelope) {
            if (!$envelope instanceof Envelope) {
                $ctx->log()->debug('Non-envelope item skipped during drain', ['item' => get_debug_type($envelope)]);

                continue;
            }

            $inner = $envelope->getMessage();
            $traceStamp = $envelope->last(TraceContextStamp::class);
            $parent = $traceStamp instanceof TraceContextStamp && $observability !== null
                ? $observability->propagator()->extract($traceStamp->carrier)
                : null;
            $span = null;

            try {
                $span = $ctx->tracer()->startSpan(
                    'messenger.receive',
                    SpanKind::Consumer,
                    [
                        'messaging.operation' => 'receive',
                        'messaging.system' => 'symfony-messenger',
                        'nexus.actor.path' => (string) $ctx->path(),
                        'nexus.message.type' => $inner::class,
                    ],
                    $parent,
                );
            } catch (Throwable) {
                // Telemetry must never break message flow.
            }

            try {
                $target = $router->route($inner, $envelope);

                if ($target === null) {
                    $outcome = self::handleUnroutable($ctx, $receiver, $config, $deadLetters, $events, $envelope);
                    $span?->setAttribute('nexus.messenger.outcome', $outcome);

                    continue;
                }

                // Ask path: both stamps present and a locator is configured.
                $corrStamp = $envelope->last(CorrelationIdStamp::class);
                $replyStamp = $envelope->last(ReplyToStamp::class);

                if (
                    $corrStamp instanceof CorrelationIdStamp
                    && $replyStamp instanceof ReplyToStamp
                    && $replySenders !== null
                ) {
                    $correlationId = $corrStamp->id;

                    // Transports that redeliver un-acked messages (e.g. InMemoryTransport in tests,
                    // real brokers with visibility timeouts) return this envelope on every get() until
                    // it is acked or rejected. Skip re-processing if we already have a pending entry.
                    if (isset($pendingAsks[$correlationId])) {
                        $span?->setAttribute('nexus.messenger.outcome', 'ask_already_pending');

                        continue;
                    }

                    $replySender = $replySenders->senderFor($replyStamp->channel);

                    if ($replySender === null) {
                        self::swallow(static fn(): mixed => $ctx->meter()->counter(
                            'nexus.messenger.asks.unroutable_reply_to',
                            '{message}',
                            'Ask envelopes rejected because the reply-to channel is not in the configured locator',
                        )->add(1, ['nexus.message.type' => $inner::class]));
                        $ctx->log()->warning(
                            'Unknown reply-to channel',
                            ['channel' => $replyStamp->channel, 'type' => $inner::class],
                        );
                        $receiver->reject($envelope);
                        $span?->setAttribute('nexus.messenger.outcome', 'reply_to_rejected');

                        continue;
                    }

                    if (!($target instanceof LocalActorRef)) {
                        // Documented limitation: non-local targets cannot carry a reply ref through
                        // their transport. Deliver as plain tell + immediate ack.
                        $ctx->log()->warning(
                            'Ask envelope delivered to non-local target; process-ack not supported — reply impossible',
                            ['target' => (string) $target->path()],
                        );
                        $target->tell($inner);
                        $receiver->ack($envelope);
                        $processed++;
                        self::swallow(static fn(): mixed => $ctx->meter()->counter(
                            'nexus.messenger.messages.consumed',
                            '{message}',
                            'Broker messages delivered to a target actor and acked',
                        )->add(1, ['nexus.message.type' => $inner::class]));
                        $span?->setAttribute('nexus.messenger.outcome', 'ask_non_local');
                        self::swallow(static fn(): mixed => $events?->dispatch(
                            new MessageConsumed($inner, (string) $target->path()),
                        ));

                        continue;
                    }

                    // LocalActorRef: create the process-ack reply ref and deliver with senderRef.
                    $fired = false;
                    $ackCallback = static function () use ($receiver, $envelope, &$fired): void {
                        if ($fired) {
                            return;
                        }

                        $fired = true;

                        try {
                            $receiver->ack($envelope);
                        } catch (Throwable) {
                            // Ack failure is a transport issue; the message will be redelivered.
                        }
                    };
                    $disarm = static function () use (&$fired): void {
                        $fired = true;
                    };

                    $replyRef = new MessengerReplyRef(
                        $replySender,
                        $correlationId,
                        $ackCallback,
                        $observability ?? new NoopObservability(),
                        $events,
                    );

                    $coreEnvelope = CoreEnvelope::of($inner, ActorPath::root(), $target->path())
                        ->withCorrelationId($correlationId)
                        ->withSenderRef($replyRef);

                    $result = $target->offerEnvelope($coreEnvelope);

                    if ($result !== EnqueueResult::Accepted) {
                        if ($result === EnqueueResult::Dropped) {
                            self::swallow(static fn(): mixed => $ctx->meter()->counter(
                                'nexus.messenger.enqueue.dropped',
                                '{message}',
                                'Broker messages left un-acked because the target mailbox was closed or dropped them',
                            )->add(1, ['nexus.message.type' => $inner::class]));
                            $ctx->log()->warning(
                                'Target mailbox dropped the message; leaving un-acked for redelivery',
                                ['type' => $inner::class],
                            );
                        } else {
                            self::swallow(static fn(): mixed => $ctx->meter()->counter(
                                'nexus.messenger.enqueue.backpressured',
                                '{message}',
                                'Broker messages not acked because the target mailbox did not accept them',
                            )->add(1, ['nexus.message.type' => $inner::class]));
                            $ctx->log()->debug(
                                'Mailbox backpressured, pausing broker consumption',
                                ['type' => $inner::class],
                            );
                        }

                        $span?->setAttribute(
                            'nexus.messenger.outcome',
                            $result === EnqueueResult::Dropped
                                ? 'dropped'
                                : 'backpressured',
                        );

                        return [$processed, true];
                    }

                    $pendingAsks[$correlationId] = [
                        'deadline' => microtime(true) + $config->askPendingTimeout->toSecondsFloat(),
                        'disarm' => $disarm,
                        'envelope' => $envelope,
                    ];
                    $processed++;
                    self::swallow(static fn(): mixed => $ctx->meter()->counter(
                        'nexus.messenger.messages.consumed',
                        '{message}',
                        'Broker messages delivered to a target actor and acked',
                    )->add(1, ['nexus.message.type' => $inner::class]));
                    $span?->setAttribute('nexus.messenger.outcome', 'ask_pending');
                    self::swallow(static fn(): mixed => $events?->dispatch(
                        new MessageConsumed($inner, (string) $target->path()),
                    ));

                    continue;
                }

                // Ask stamps present but no locator: deliver as plain tell with one-time warning.
                if (
                    $corrStamp instanceof CorrelationIdStamp
                    && $replyStamp instanceof ReplyToStamp
                    && !$warnedNoLocator
                ) {
                    $warnedNoLocator = true;
                    $ctx->log()->warning('Ask received but no ReplySenderLocator configured');
                }

                // Normal path.
                if ($target instanceof BackpressureCapable) {
                    $result = $target->offer($inner);

                    if ($result !== EnqueueResult::Accepted) {
                        if ($result === EnqueueResult::Dropped) {
                            self::swallow(static fn(): mixed => $ctx->meter()->counter(
                                'nexus.messenger.enqueue.dropped',
                                '{message}',
                                'Broker messages left un-acked because the target mailbox was closed or dropped them',
                            )->add(1, ['nexus.message.type' => $inner::class]));
                            $ctx->log()->warning(
                                'Target mailbox dropped the message; leaving un-acked for redelivery',
                                ['type' => $inner::class],
                            );
                        } else {
                            self::swallow(static fn(): mixed => $ctx->meter()->counter(
                                'nexus.messenger.enqueue.backpressured',
                                '{message}',
                                'Broker messages not acked because the target mailbox did not accept them',
                            )->add(1, ['nexus.message.type' => $inner::class]));
                            $ctx->log()->debug(
                                'Mailbox backpressured, pausing broker consumption',
                                ['type' => $inner::class],
                            );
                        }

                        $span?->setAttribute(
                            'nexus.messenger.outcome',
                            $result === EnqueueResult::Dropped
                                ? 'dropped'
                                : 'backpressured',
                        );

                        return [$processed, true];
                    }
                } else {
                    $target->tell($inner);
                }

                $receiver->ack($envelope);
                $processed++;
                self::swallow(static fn(): mixed => $ctx->meter()->counter(
                    'nexus.messenger.messages.consumed',
                    '{message}',
                    'Broker messages delivered to a target actor and acked',
                )->add(1, ['nexus.message.type' => $inner::class]));
                $span?->setAttribute('nexus.messenger.outcome', 'acked');
                self::swallow(
                    static fn(): mixed => $events?->dispatch(new MessageConsumed($inner, (string) $target->path())),
                );
            } finally {
                $span?->end();
            }
        }

        return [$processed, false];
    }

    /**
     * Expire pending ask entries that have exceeded the configured timeout, rejecting
     * their broker envelopes for redelivery and disarming the ack callback.
     *
     * @param ActorContext<object> $ctx
     * @param array<string, array{deadline: float, disarm: \Closure(): void, envelope: Envelope}> $pendingAsks
     */
    private static function expirePendingAsks(
        ActorContext $ctx,
        ReceiverInterface $receiver,
        array &$pendingAsks,
    ): void {
        if ($pendingAsks === []) {
            return;
        }

        $now = microtime(true);

        foreach ($pendingAsks as $correlationId => $entry) {
            if ($now <= $entry['deadline']) {
                continue;
            }

            ($entry['disarm'])();
            self::swallow(static fn(): mixed => $receiver->reject($entry['envelope']));
            self::swallow(static fn(): mixed => $ctx->meter()->counter(
                'nexus.messenger.asks.responder_expired',
                '{message}',
                'Ask envelopes rejected for redelivery because the responder did not reply within the deadline',
            )->add(1));
            $ctx->log()->warning(
                'Ask responder did not reply within deadline; rejecting for redelivery',
                ['correlation_id' => $correlationId],
            );

            unset($pendingAsks[$correlationId]);
        }
    }

    /**
     * @param ActorContext<object> $ctx
     * @param ActorRef<object>|null $deadLetters
     * @return string the span outcome attribute value
     */
    private static function handleUnroutable(
        ActorContext $ctx,
        ReceiverInterface $receiver,
        ReceiverActorConfig $config,
        ?ActorRef $deadLetters,
        ?EventDispatcherInterface $events,
        Envelope $envelope,
    ): string {
        $inner = $envelope->getMessage();
        $ctx->log()->warning('Unroutable messenger message', [
            'policy' => $config->unroutablePolicy->name,
            'type' => $inner::class,
        ]);

        if ($config->unroutablePolicy === UnroutablePolicy::DeadLetters && $deadLetters !== null) {
            $deadLetters->tell($inner);
            $receiver->ack($envelope);
            self::swallow(static fn(): mixed => $ctx->meter()->counter(
                'nexus.messenger.messages.dead_lettered',
                '{message}',
                'Unroutable broker messages forwarded to dead letters',
            )->add(1, ['nexus.message.type' => $inner::class]));
            self::swallow(static fn(): mixed => $events?->dispatch(new MessageDeadLettered($inner)));

            return 'dead_lettered';
        }

        $receiver->reject($envelope);
        self::swallow(static fn(): mixed => $ctx->meter()->counter(
            'nexus.messenger.messages.rejected',
            '{message}',
            'Unroutable broker messages rejected back to the transport',
        )->add(1, ['nexus.message.type' => $inner::class]));
        self::swallow(static fn(): mixed => $events?->dispatch(new MessageRejected($inner)));

        return 'rejected';
    }

    /**
     * @param callable(): mixed $fn
     */
    private static function swallow(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable) {
            // Telemetry must never break message flow.
        }
    }
}
