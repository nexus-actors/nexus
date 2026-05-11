<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Idempotency;

use Monadial\Nexus\Ddd\Messaging\Envelope\Stamp;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Carries an `IdempotencyReservation` across the OCC retry boundary.
 * `IdempotencyReserveMiddleware` stamps the envelope after a successful
 * `tryReserve`; `IdempotencyCommitMiddleware` reads it back inside the
 * handler TX to call `markCompleted`.
 */
final readonly class ReservationStamp implements Stamp
{
    public function __construct(public IdempotencyReservation $reservation) {}
}
