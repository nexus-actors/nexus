<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Middleware;

use Closure;
use Monadial\Nexus\Ddd\Bus\Idempotency\IdempotencyKeyResolver;
use Monadial\Nexus\Ddd\Bus\Idempotency\IdempotencyStore;
use Monadial\Nexus\Ddd\Bus\Idempotency\ReservationStamp;
use Monadial\Nexus\Ddd\Bus\Profile\Profile;
use Monadial\Nexus\Ddd\Bus\Routing\HandlerAttributeIndex;
use Monadial\Nexus\Ddd\Bus\Routing\ResolvedAttributesEntry;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Exception\TerminalFailure;
use Override;
use Throwable;

/**
 * @psalm-api
 *
 * Outer half of the two-phase idempotency split. Runs OUTSIDE the OCC
 * retry loop so retries reuse the same reservation token.
 *
 * Self-disables under `Profile::Sync` (per panel H6) — sync profile has no
 * redelivery surface, so reservation would be a no-op cost. Under
 * Async/Actor, the reserve+commit pair gates redelivery dedup.
 *
 * Exception classification (per panel B3):
 *   - `TerminalFailure` (validation, access-denied): `markCompleted($token)`.
 *     Negative outcome persists as a dedup row so redelivery short-circuits.
 *   - `RetryableFailure` (OCC, transient infra) + any other `Throwable`
 *     (uncategorized infrastructure): `release($token)` so a future redelivery
 *     can re-attempt.
 *
 * Short-circuit branch: when `tryReserve` returns `Option::none()` the
 * message has already been handled — `process()` returns `null` (the `mixed`
 * declared return type permits null; this is the dispatcher's no-op signal,
 * not a "null value" leaking through Option's contract).
 *
 * @template TIn of object
 * @template TOut
 * @implements Middleware<TIn, TOut>
 */
final class IdempotencyReserveMiddleware implements Middleware
{
    public function __construct(
        private readonly IdempotencyStore $store,
        private readonly IdempotencyKeyResolver $resolver,
        private readonly HandlerAttributeIndex $index,
        private readonly Profile $profile,
    ) {}

    #[Override]
    public function process(Envelope $envelope, Closure $next): mixed
    {
        if ($this->profile === Profile::Sync) {
            return $next($envelope);
        }

        $entry = $this->index->lookup($envelope->message::class);

        if ($entry->isSome() && $entry->getUnsafe()->isIdempotencyOptedOut()) {
            return $next($envelope);
        }

        /** @var class-string $handlerClass */
        $handlerClass = $entry
            ->map(static fn(ResolvedAttributesEntry $e): string => $e->handlerClass())
            ->getOrElse('unknown');
        $key = $this->resolver->resolve($envelope);
        $reservation = $this->store->tryReserve($handlerClass, $key);

        if ($reservation->isNone()) {
            return null;
        }

        $token = $reservation->getUnsafe();
        $stampedEnvelope = $envelope->with(new ReservationStamp($token));

        try {
            return $next($stampedEnvelope);
        } catch (Throwable $e) {
            if ($e instanceof TerminalFailure) {
                $this->store->markCompleted($token);
            } else {
                $this->store->release($token);
            }

            throw $e;
        }
    }
}
