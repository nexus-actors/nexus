<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Idempotency;

use Fp\Functional\Option\Option;
use Monadial\Duration\FiniteDuration;

/**
 * @psalm-api
 *
 * Two-phase idempotency contract. Per umbrella spec §13.1:
 *   tryReserve     — gates redelivery; returns Some(token) on first.
 *   markCompleted  — durable commit; runs INSIDE handler TX.
 *   release        — release on failure to allow future redelivery.
 *
 * The pipeline calls `tryReserve` in `IdempotencyReserveMiddleware` (outer;
 * before OCC retry); the OCC retry loop reuses the SAME token across
 * attempts; `markCompleted` runs in `IdempotencyCommitMiddleware` (inner;
 * post-handler, pre-flush, INSIDE the TX).
 */
interface IdempotencyStore
{
    /**
     * @param class-string $handlerClass
     * @return Option<IdempotencyReservation> None means "already handled" — caller short-circuits.
     */
    public function tryReserve(string $handlerClass, IdempotencyKey $key): Option;

    public function markCompleted(IdempotencyReservation $token): void;

    public function release(IdempotencyReservation $token): void;

    /**
     * Minimum TTL for committed reservations. Bus boot validation requires
     * `ttl() >= max retry budget` across all profiles to prevent
     * stale-eviction during in-flight retry sequences.
     */
    public function ttl(): FiniteDuration;
}
