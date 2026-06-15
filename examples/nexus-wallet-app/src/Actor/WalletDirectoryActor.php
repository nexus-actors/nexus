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
 * absent, and replies with a WalletRef. Children inherit the directory's
 * supervisor — a failed wallet restarts via the default one-for-one
 * strategy without taking out the directory or its siblings.
 *
 * @psalm-type RegistryState = array<string, \Monadial\Nexus\Core\Actor\ActorRef<object>>
 */
final readonly class WalletDirectoryActor
{
    public static function behavior(EventStore $eventStore): Behavior
    {
        return Behavior::withState(
            /** @var RegistryState */
            initialState: [],
            handler: static function (
                ActorContext $ctx,
                object $message,
                array $registry,
            ) use ($eventStore): BehaviorWithState {
                if (!$message instanceof EnsureWallet) {
                    return BehaviorWithState::same();
                }

                $existing = $registry[$message->ownerId] ?? null;

                if ($existing !== null && $existing->isAlive()) {
                    $message->replyTo->tell(new WalletRef($existing));

                    return BehaviorWithState::same();
                }

                $child = $ctx->spawn(
                    Props::fromBehavior(WalletActor::behavior($message->ownerId, $eventStore)),
                    'wallet-' . $message->ownerId,
                );

                $registry[$message->ownerId] = $child;
                $message->replyTo->tell(new WalletRef($child));

                return BehaviorWithState::next($registry);
            },
        );
    }
}
