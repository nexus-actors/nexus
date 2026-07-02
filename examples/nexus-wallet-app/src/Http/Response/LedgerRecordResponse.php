<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Http\Response;

use Monadial\Nexus\Example\Wallet\Domain\LedgerKind;

/** @psalm-api */
final readonly class LedgerRecordResponse
{
    public function __construct(
        public string $ownerId,
        public LedgerKind $kind,
        public int $amountCents,
        public string $status = 'recorded',
    ) {}
}
