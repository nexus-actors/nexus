<?php

declare(strict_types=1);

namespace Examples\HttpOrders\Domain;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\BehaviorWithState;

/**
 * In-memory orders store. Replies via $ctx->sender()->tell($reply).
 */
final class OrderActor
{
    /**
     * @return Behavior<object>
     *
     * @psalm-suppress InvalidArgument illustrative example: BehaviorWithState::same/next have looser generics than the closure's S
     * @psalm-suppress MixedArgumentTypeCoercion
     */
    public static function create(): Behavior
    {
        return Behavior::withState(
            OrderStore::empty(),
            static function (ActorContext $ctx, object $msg, OrderStore $state): BehaviorWithState {
                if ($msg instanceof CreateOrder) {
                    $order = new Order($state->nextId, $msg->sku, $msg->qty);

                    $sender = $ctx->sender();

                    if ($sender !== null) {
                        $sender->tell($order);
                    }

                    return BehaviorWithState::next($state->withOrder($order));
                }

                if ($msg instanceof GetOrder) {
                    $sender = $ctx->sender();

                    if ($sender !== null) {
                        $reply = $state->orders[$msg->id] ?? new OrderNotFound($msg->id);
                        $sender->tell($reply);
                    }

                    return BehaviorWithState::same();
                }

                if ($msg instanceof DeleteOrder) {
                    return BehaviorWithState::next($state->without($msg->id));
                }

                return BehaviorWithState::same();
            },
        );
    }
}
