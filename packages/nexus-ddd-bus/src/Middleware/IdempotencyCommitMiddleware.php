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
 * TX, AFTER `HandlerInvocation`, BEFORE `EventDrain` flush. `markCompleted`
 * lands or rolls back atomically with the handler's writes (per umbrella
 * spec §13.1).
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
        $result = $next($envelope);

        if ($this->profile === Profile::Sync) {
            return $result;
        }

        $stamp = $envelope->get(ReservationStamp::class);

        if ($stamp->isSome()) {
            $this->store->markCompleted($stamp->getUnsafe()->reservation);
        }

        return $result;
    }
}
