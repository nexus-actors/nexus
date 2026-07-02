<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Http\Request;

/**
 * Body shape `{ "amountCents": int }` used by deposit and withdraw.
 * Valinor enforces type + presence — handlers receive a typed instance,
 * not an associative array, and don't need to cast.
 *
 * @psalm-api
 */
final readonly class AmountRequest
{
    public function __construct(public int $amountCents) {}
}
