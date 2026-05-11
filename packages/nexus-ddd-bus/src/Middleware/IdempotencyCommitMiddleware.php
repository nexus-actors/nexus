<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Middleware;

use Closure;
use Monadial\Nexus\Ddd\Bus\Idempotency\IdempotencyStore;
use Monadial\Nexus\Ddd\Bus\Idempotency\ReservationStamp;
use Monadial\Nexus\Ddd\Bus\Profile\Profile;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Override;

/**
 * @psalm-api
 *
 * Inner half of the two-phase idempotency split. Runs INSIDE the handler
 * TX, AFTER `HandlerInvocation`, BEFORE `EventDrain` flush. Per umbrella
 * spec §13.1 + panel B2: `markCompleted` runs BEFORE `$next` so the dedup
 * row is durable before `EventDrain` flushes the outbox — otherwise an
 * async relay polling between flush and mark could double-deliver. By the
 * time this middleware is entered, the upstream `Handler` middleware has
 * already invoked the handler successfully, so committing now reflects
 * the handler's success.
 *
 * Self-disables under `Profile::Sync` (mirrors `IdempotencyReserveMiddleware`
 * H6) — the Reserve middleware doesn't reserve under Sync, so there's no
 * token to commit. If a stamp is absent (e.g., opted-out handler, sync
 * bypass), `markCompleted` is skipped.
 *
 * @template TIn of object
 * @template TOut
 * @implements Middleware<TIn, TOut>
 */
final class IdempotencyCommitMiddleware implements Middleware
{
    public function __construct(private readonly IdempotencyStore $store, private readonly Profile $profile) {}

    #[Override]
    public function process(Envelope $envelope, Closure $next): mixed
    {
        if ($this->profile === Profile::Sync) {
            return $next($envelope);
        }

        $stamp = $envelope->get(ReservationStamp::class);

        if ($stamp->isSome()) {
            $this->store->markCompleted($stamp->getUnsafe()->reservation);
        }

        return $next($envelope);
    }
}
