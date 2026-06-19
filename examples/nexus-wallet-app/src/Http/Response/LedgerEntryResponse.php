<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Http\Response;

use Monadial\Nexus\Example\Wallet\Domain\LedgerKind;

/** @psalm-api */
final readonly class LedgerEntryResponse
{
    public function __construct(
        public ?int $id,
        public LedgerKind $kind,
        public int $amountCents,
        public string $occurredAt,
    ) {}
}
