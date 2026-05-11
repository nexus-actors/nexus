<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Support;

use Fp\Functional\Option\Option;
use Monadial\Duration\FiniteDuration;
use Monadial\Duration\TimeUnit\TimeUnit;
use Monadial\Nexus\Ddd\Bus\Idempotency\IdempotencyKey;
use Monadial\Nexus\Ddd\Bus\Idempotency\IdempotencyReservation;
use Monadial\Nexus\Ddd\Bus\Idempotency\IdempotencyStore;
use Override;

/**
 * Test fixture: `tryReserve` always returns `Option::none()` — simulates
 * the "already handled" branch for short-circuit assertions. The
 * mark/release methods are no-ops; tests asserting them shouldn't reach
 * this fixture.
 *
 * @psalm-api
 */
final class AlwaysNoneStore implements IdempotencyStore
{
    #[Override]
    public function tryReserve(string $handlerClass, IdempotencyKey $key): Option
    {
        return Option::none();
    }

    #[Override]
    public function markCompleted(IdempotencyReservation $token): void
    {
        // No-op: this fixture exists to test the "already handled" short-circuit; the middleware never reaches mark/release.
    }

    #[Override]
    public function release(IdempotencyReservation $token): void
    {
        // No-op: see markCompleted.
    }

    #[Override]
    public function ttl(): FiniteDuration
    {
        return FiniteDuration::fromTimeUnit(30, TimeUnit::Days());
    }
}
