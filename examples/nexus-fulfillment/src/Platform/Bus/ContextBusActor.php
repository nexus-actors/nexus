<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Platform\Bus;

use Monadial\Nexus\Core\Actor\ActorContext;
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
     * @psalm-suppress MixedAssignment -- $subscriber from untyped list<ActorRef<object>>
     * @psalm-suppress MixedMethodCall -- $subscriber is ActorRef<object> at runtime; type erased by design
     */
    public static function behavior(): Behavior
    {
        return Behavior::withState(
            [],
            static function (ActorContext $ctx, object $msg, array $subscribers): BehaviorWithState {
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
