<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Inventory\Domain;

use Monadial\Nexus\Example\Fulfillment\SharedKernel\Quantity;

/**
 * Named domain concept (Evans): encapsulates the business rule that
 * governs whether a reservation may proceed. Kept as its own class so the
 * rule can be referenced by name in tests and documentation.
 */
final class ReservationPolicy
{
    public static function allows(ItemState $state, Quantity $requested): bool
    {
        return $requested->value <= $state->available();
    }
}
