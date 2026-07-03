<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\MessengerRedis\Actor;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorHandler;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Example\MessengerRedis\Message\OrderPlaced;

/**
 * Handles incoming OrderPlaced messages delivered by the ReceiverActor.
 *
 * In a real application this actor would persist the order, publish downstream
 * events, call a payment actor, etc. Here it logs to stdout so the
 * competing-consumer pattern is visible at a glance.
 */
final class OrderProcessor implements ActorHandler
{
    public function handle(ActorContext $ctx, object $message): Behavior
    {
        if ($message instanceof OrderPlaced) {
            $ctx->log()->info(
                '[OrderProcessor] received order',
                [
                    'actor' => (string) $ctx->self()->path(),
                    'amount_cents' => $message->amountCents,
                    'customer_id' => $message->customerId,
                    'order_id' => $message->orderId,
                ],
            );
        }

        return Behavior::same();
    }
}
