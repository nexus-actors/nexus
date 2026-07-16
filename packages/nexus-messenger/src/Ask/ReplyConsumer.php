<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Ask;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Messenger\Consumer\Poll;
use Monadial\Nexus\Messenger\Event\AskResolved;
use Monadial\Nexus\Messenger\Stamp\CorrelationIdStamp;
use Monadial\Nexus\Runtime\Duration;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Throwable;

/**
 * Dedicated slim poll-loop behavior that receives reply envelopes and resolves
 * pending ask futures.
 *
 * Mirrors the ReceiverActor polling shape but routes nothing — replies are
 * resolved directly against the {@see PendingAskRegistry} using the
 * {@see CorrelationIdStamp}. Acks ALWAYS: a missing stamp or an unknown /
 * duplicate correlation ID causes the envelope to be acked and dropped (with a
 * counter increment) rather than left un-acked. This prevents reply queues from
 * accumulating undeliverable messages.
 *
 * Busy/idle scheduling: when at least one reply was processed in a tick the next
 * poll fires immediately (re-drain); when the receiver returns nothing the next
 * poll is deferred by {@see Duration $pollInterval}.
 *
 * This class is intentionally internal — create the behavior via
 * {@see ReplyConsumer::create()} and let {@see AskSupport} spawn it.
 *
 * @internal created by AskSupport
 *
 * A case-less enum: uninstantiable by the language, exists purely as a
 * namespace for the static behavior factory.
 */
enum ReplyConsumer
{
    /**
     * @return Behavior<object>
     */
    public static function create(
        ReplyChannel $channel,
        PendingAskRegistry $registry,
        Duration $pollInterval,
        ?EventDispatcherInterface $events = null,
    ): Behavior {
        return Behavior::setup(
            /**
             * @param ActorContext<object> $ctx
             * @return Behavior<object>
             */
            static function (ActorContext $ctx) use ($channel, $registry, $pollInterval, $events): Behavior {
                $ctx->self()->tell(new Poll());

                // Register an observable gauge for the pending ask count.
                self::swallow(static fn(): mixed => $ctx->meter()->observableGauge(
                    'nexus.messenger.asks.pending',
                    static fn(): int => $registry->count(),
                    '{ask}',
                    'Pending ask requests waiting for a reply',
                ));

                return Behavior::receive(
                    /**
                     * @param ActorContext<object> $ctx
                     * @return Behavior<object>
                     */
                    static function (ActorContext $ctx, object $message) use ($channel, $registry, $pollInterval, $events): Behavior {
                        if (!$message instanceof Poll) {
                            return Behavior::unhandled();
                        }

                        $processed = self::drainReplies($ctx, $channel, $registry, $events);

                        if ($processed > 0) {
                            $ctx->self()->tell(new Poll());
                        } else {
                            $ctx->scheduleOnce($pollInterval, new Poll());
                        }

                        return Behavior::same();
                    },
                );
            },
        );
    }

    /**
     * @param ActorContext<object> $ctx
     * @return int messages processed this tick
     */
    private static function drainReplies(
        ActorContext $ctx,
        ReplyChannel $channel,
        PendingAskRegistry $registry,
        ?EventDispatcherInterface $events,
    ): int {
        $processed = 0;

        foreach ($channel->receiver()->get() as $envelope) {
            if (!$envelope instanceof Envelope) {
                $ctx->log()->debug('Non-envelope item skipped in reply consumer', [
                    'item' => get_debug_type($envelope),
                ]);

                continue;
            }

            $corrStamp = $envelope->last(CorrelationIdStamp::class);

            if (!$corrStamp instanceof CorrelationIdStamp) {
                // Missing stamp: ack and drop — cannot route without a correlation ID.
                $ctx->log()->debug('Reply envelope missing CorrelationIdStamp; acking and dropping');
                self::swallow(static fn(): mixed => $channel->receiver()->ack($envelope));
                self::swallow(static fn(): mixed => $ctx->meter()->counter(
                    'nexus.messenger.replies.dropped',
                    '{message}',
                    'Reply envelopes dropped because they had no CorrelationIdStamp',
                )->add(1));
                $processed++;

                continue;
            }

            $inner = $envelope->getMessage();
            $resolved = $registry->resolve($corrStamp->id, $inner);

            if ($resolved) {
                self::swallow(static fn(): mixed => $ctx->meter()->counter(
                    'nexus.messenger.asks.resolved',
                    '{ask}',
                    'Ask requests successfully resolved with a reply',
                )->add(1));
                self::swallow(static fn(): mixed => $events?->dispatch(new AskResolved($corrStamp->id, $inner)));
            } else {
                // Duplicate or late reply: no pending slot — ack and drop.
                $ctx->log()->debug(
                    'Reply dropped: no pending ask matched the correlation ID',
                    ['correlation_id' => $corrStamp->id],
                );
                self::swallow(static fn(): mixed => $ctx->meter()->counter(
                    'nexus.messenger.replies.dropped',
                    '{message}',
                    'Reply envelopes dropped because no pending ask matched the correlation ID',
                )->add(1));
            }

            // Ack ALWAYS — late replies are consumed and discarded, never requeued.
            self::swallow(static fn(): mixed => $channel->receiver()->ack($envelope));
            $processed++;
        }

        return $processed;
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
