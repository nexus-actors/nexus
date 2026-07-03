<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Platform\Bus;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\BehaviorWithState;

/**
 * In-process fan-out between bounded contexts — the seam a broker
 * (Messenger bridge) replaces when contexts split into services.
 * Delivery is at-most-once, in-process, fire-and-forget by design.
 */
final class ContextBusActor
{
    /**
     * @psalm-suppress InvalidArgument -- heterogeneous subscriber array; S infers as mixed-array union from same()/next() branches
     * @psalm-suppress MixedArgumentTypeCoercion -- same cause: S resolves to array|non-empty-array across branches
     */
    public static function behavior(): Behavior
    {
        /** @var list<ActorRef<object>> $empty */
        $empty = [];

        return Behavior::withState(
            $empty,
            /**
             * @param list<ActorRef<object>> $subscribers
             * @psalm-suppress UntypedActorRefInjection -- the bus is intentionally heterogeneous
             */
            static function (ActorContext $ctx, object $msg, $subscribers): BehaviorWithState {
                if ($msg instanceof Subscribe) {
                    $subscribers[] = $msg->subscriber;

                    return BehaviorWithState::next($subscribers);
                }

                if ($msg instanceof Publish) {
                    foreach ($subscribers as $subscriber) {
                        $subscriber->tell($msg->event);
                    }

                    return BehaviorWithState::same();
                }

                return BehaviorWithState::same();
            },
        );
    }
}
