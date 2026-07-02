<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Http\Response;

/** @psalm-api */
final readonly class LedgerEntriesResponse
{
    /**
     * @param list<LedgerEntryResponse> $entries
     */
    public function __construct(public string $ownerId, public int $count, public array $entries) {}
}
