<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Domain\Command;

use Monadial\Nexus\Example\Wallet\Domain\LedgerKind;

/**
 * Tells a per-owner `LedgerActor` to record one ledger entry. Sent to
 * the actor by the `Ledger*Handler` HTTP handlers via `EntityRefFactory`.
 *
 * The actor's command handler matches on `$kind` and mutates the entity's
 * running totals.
 */
final readonly class RecordLedger implements LedgerCommand
{
    public function __construct(public LedgerKind $kind, public int $amountCents) {}
}
