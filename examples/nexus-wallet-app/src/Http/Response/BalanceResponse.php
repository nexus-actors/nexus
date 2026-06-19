<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Http\Response;

/** @psalm-api */
final readonly class BalanceResponse
{
    public function __construct(public string $ownerId, public int $balance) {}
}
