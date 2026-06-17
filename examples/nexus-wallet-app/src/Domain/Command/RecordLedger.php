<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Domain\Command;

/**
 * Tells a per-owner `LedgerActor` to record one ledger entry. Sent to
 * the actor by the `Ledger*Handler` HTTP handlers via `EntityRefFactory`.
 *
 * `$kind` is one of `deposit` | `withdraw` — the actor's command handler
 * matches on it and mutates the entity's running totals.
 */
final readonly class RecordLedger
{
    public function __construct(
        public string $kind,
        public int $amountCents,
    ) {}
}
