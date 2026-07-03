<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Consumer;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\BackpressureCapable;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Messenger\Event\MessageConsumed;
use Monadial\Nexus\Messenger\Event\MessageDeadLettered;
use Monadial\Nexus\Messenger\Event\MessageRejected;
use Monadial\Nexus\Messenger\Lifecycle\MessagesProcessed;
use Monadial\Nexus\Messenger\Routing\MessageRouter;
use Monadial\Nexus\Messenger\Stamp\TraceContextStamp;
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
    ): Behavior {
        $config ??= ReceiverActorConfig::default();

        return Behavior::setup(
            static function (ActorContext $ctx) use ($receiver, $router, $config, $deadLetters, $processedListener, $events, $observability): Behavior {
                $ctx->self()->tell(new Poll());

                return Behavior::receive(
                    static function (ActorContext $ctx, object $message) use ($receiver, $router, $config, $deadLetters, $processedListener, $events, $observability): Behavior {
                        if (!$message instanceof Poll) {
                            return Behavior::unhandled();
                        }

                        [$processed, $backpressured] = self::drainOnce(
                            $ctx,
                            $receiver,
                            $router,
                            $config,
                            $deadLetters,
                            $events,
                            $observability,
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
     * @return array{0: int, 1: bool} messages acked this tick, whether the tick stopped on backpressure
     */
    private static function drainOnce(
        ActorContext $ctx,
        ReceiverInterface $receiver,
        MessageRouter $router,
        ReceiverActorConfig $config,
        ?ActorRef $deadLetters,
        ?EventDispatcherInterface $events,
        ?Observability $observability = null,
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
