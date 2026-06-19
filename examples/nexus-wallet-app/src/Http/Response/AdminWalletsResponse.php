<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Http\Response;

/** @psalm-api */
final readonly class AdminWalletsResponse
{
    /**
     * @param list<AdminWalletSummary> $wallets
     */
    public function __construct(public int $count, public array $wallets) {}
}
