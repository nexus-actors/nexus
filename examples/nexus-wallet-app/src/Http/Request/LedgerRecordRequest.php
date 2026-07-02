<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Http\Request;

use Monadial\Nexus\Example\Wallet\Domain\LedgerKind;

/**
 * Body shape `{ "kind": "deposit"|"withdraw", "amountCents": int }`.
 * Valinor maps the literal string onto the {@see LedgerKind} enum at the
 * boundary — unknown values fail mapping (caught and turned into 400)
 * so the handler can trust the enum.
 *
 * @psalm-api
 */
final readonly class LedgerRecordRequest
{
    public function __construct(public LedgerKind $kind, public int $amountCents) {}
}
