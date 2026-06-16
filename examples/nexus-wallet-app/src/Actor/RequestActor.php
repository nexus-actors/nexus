<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Actor;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;

/**
 * Actor-per-request observer.
 *
 * Registered via `$app->perRequestActor('request', …)` — the http
 * dispatcher spawns a fresh instance for every inbound request and
 * stops it after the response is written. The actor's lifetime is
 * bounded by a single HTTP request, which makes it the right place
 * for request-scoped state (correlation ids, idempotency keys,
 * rate-limit tokens, transient retry counters).
 *
 * Critical Swoole pitfall: do NOT use `ask()->await()` chains inside
 * an actor receive handler when those chains span the same coroutine
 * pool as the HTTP request loop — the inflight asks consume coroutine
 * slots and a 3-deep chain (handler → request → directory → wallet)
 * starves the pool and deadlocks.
 *
 * Instead, the HTTP handler does the directory/wallet asks DIRECTLY,
 * and uses this actor purely for fire-and-forget side effects
 * (audit log, metric increment, correlation-id propagation). That
 * keeps the actor lifecycle visible while preserving forward progress.
 */
final readonly class RequestActor
{
    public static function behavior(): Behavior
    {
        return Behavior::receive(
            static function (ActorContext $ctx, object $message): Behavior {
                if ($message instanceof HandleRequest) {
                    $ctx->log()->info('request observed', [
                        'ownerId' => $message->ownerId,
                        'action' => $message->action,
                        'amountCents' => $message->amountCents,
                    ]);
                }

                return Behavior::same();
            },
        );
    }
}
