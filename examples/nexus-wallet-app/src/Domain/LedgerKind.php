<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Domain;

/**
 * Operation recorded on the per-owner Doctrine ledger.
 *
 * Same vocabulary as the event-sourced wallet (`deposit`/`withdraw`)
 * — kept as a single backed enum so the HTTP body decoder, the
 * `RecordLedger` command, and the `LedgerEntry` row all agree on the
 * spelling and Valinor catches unknown values at the boundary.
 *
 * @psalm-api
 */
enum LedgerKind: string
{
    case Deposit = 'deposit';

    case Withdraw = 'withdraw';
}
