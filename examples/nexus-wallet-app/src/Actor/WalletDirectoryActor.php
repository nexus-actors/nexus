<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Actor;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Persistence\Event\EventStore;

/**
 * Long-lived router that spawns and caches per-owner WalletActor children.
 *
 * Registered once at startup via `$app->actor('wallets', …)`. On
 * EnsureWallet it looks up the cached child by ownerId, spawns it if
 * absent, and replies via `$ctx->reply(new WalletRef($child))`.
 *
 * Children inherit the directory's supervisor — a failed wallet
 * restarts via the default one-for-one strategy without taking out
 * the directory or its siblings.
 */
final readonly class WalletDirectoryActor
{
    /**
     * @return Behavior<object>
     */
    public static function behavior(EventStore $eventStore): Behavior
    {
        /** @var Behavior<object> */
        return Behavior::withState(
            initialState: new WalletRegistry(),
            handler: /**
                 * @param ActorContext<object> $ctx
                 * @return BehaviorWithState<object, WalletRegistry>
                 */
                static function (
                    ActorContext $ctx,
                    object $message,
                    WalletRegistry $registry,
                ) use ($eventStore): BehaviorWithState {
                    if (!$message instanceof EnsureWallet) {
                        return BehaviorWithState::next($registry);
                    }

                    $existing = $registry->find($message->ownerId);

                    if ($existing !== null && $existing->isAlive()) {
                        $ctx->reply(new WalletRef($existing));

                        return BehaviorWithState::next($registry);
                    }

                    $child = $ctx->spawn(
                        Props::fromBehavior(WalletActor::behavior($message->ownerId, $eventStore)),
                        'wallet-' . $message->ownerId,
                    );

                    $ctx->reply(new WalletRef($child));

                    return BehaviorWithState::next($registry->with($message->ownerId, $child));
                },
        );
    }
}
