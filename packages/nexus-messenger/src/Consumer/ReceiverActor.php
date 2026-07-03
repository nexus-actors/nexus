<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Consumer;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\BackpressureCapable;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Messenger\Lifecycle\MessagesProcessed;
use Monadial\Nexus\Messenger\Routing\MessageRouter;
use Monadial\Nexus\Runtime\Mailbox\EnqueueResult;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

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
     */
    public static function create(
        ReceiverInterface $receiver,
        MessageRouter $router,
        ?ReceiverActorConfig $config = null,
        ?ActorRef $deadLetters = null,
        ?ActorRef $processedListener = null,
    ): Behavior {
        $config ??= ReceiverActorConfig::default();

        return Behavior::setup(
            static function (ActorContext $ctx) use ($receiver, $router, $config, $deadLetters, $processedListener): Behavior {
                $ctx->self()->tell(new Poll());

                return Behavior::receive(
                    static function (ActorContext $ctx, object $message) use ($receiver, $router, $config, $deadLetters, $processedListener): Behavior {
                        if (!$message instanceof Poll) {
                            return Behavior::unhandled();
                        }

                        [$processed, $backpressured] = self::drainOnce($receiver, $router, $config, $deadLetters);

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
     * @param ActorRef<object>|null $deadLetters
     * @return array{0: int, 1: bool} messages acked this tick, whether the tick stopped on backpressure
     */
    private static function drainOnce(
        ReceiverInterface $receiver,
        MessageRouter $router,
        ReceiverActorConfig $config,
        ?ActorRef $deadLetters,
    ): array {
        $processed = 0;

        foreach ($receiver->get() as $envelope) {
            if (!$envelope instanceof Envelope) {
                continue;
            }

            $inner = $envelope->getMessage();
            $target = $router->route($inner, $envelope);

            if ($target === null) {
                self::handleUnroutable($receiver, $config, $deadLetters, $envelope);

                continue;
            }

            if ($target instanceof BackpressureCapable) {
                if ($target->offer($inner) !== EnqueueResult::Accepted) {
                    return [$processed, true];
                }
            } else {
                $target->tell($inner);
            }

            $receiver->ack($envelope);
            $processed++;
        }

        return [$processed, false];
    }

    /**
     * @param ActorRef<object>|null $deadLetters
     */
    private static function handleUnroutable(
        ReceiverInterface $receiver,
        ReceiverActorConfig $config,
        ?ActorRef $deadLetters,
        Envelope $envelope,
    ): void {
        if ($config->unroutablePolicy === UnroutablePolicy::DeadLetters && $deadLetters !== null) {
            $deadLetters->tell($envelope->getMessage());
            $receiver->ack($envelope);

            return;
        }

        $receiver->reject($envelope);
    }
}
