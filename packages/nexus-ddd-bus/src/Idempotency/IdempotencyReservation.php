<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Idempotency;

/**
 * @psalm-api
 *
 * Two-phase reservation token. Issued by `IdempotencyStore::tryReserve`;
 * passed back to `markCompleted` (handler success path, INSIDE the TX) or
 * `release` (failure path).
 *
 * Each store ships its own concrete reservation type carrying impl-private
 * state (e.g., row id, lock token, composite key). Public observers see only
 * the (handlerClass, idempotencyKey) pair.
 */
interface IdempotencyReservation
{
    /** @return class-string */
    public function handlerClass(): string;

    public function idempotencyKey(): IdempotencyKey;
}
