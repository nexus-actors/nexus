<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Http\Response;

/** @psalm-api */
final readonly class AdminWalletSummary
{
    public function __construct(
        public string $ownerId,
        public int $depositCents,
        public int $depositCount,
        public int $withdrawCents,
        public int $withdrawCount,
        public int $netCents,
        public ?string $lastActivityAt,
    ) {}
}
