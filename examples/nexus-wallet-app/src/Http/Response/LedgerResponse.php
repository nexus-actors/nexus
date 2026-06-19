<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Http\Response;

/**
 * Denormalised running totals for one owner's ledger row.
 *
 * @psalm-api
 */
final readonly class LedgerResponse
{
    public function __construct(
        public string $ownerId,
        public int $depositCents,
        public int $depositCount,
        public int $withdrawCents,
        public int $withdrawCount,
        public ?string $lastActivityAt,
    ) {}
}
