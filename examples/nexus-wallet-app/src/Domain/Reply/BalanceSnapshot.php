<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Domain\Reply;

final readonly class BalanceSnapshot
{
    public function __construct(public int $balanceCents) {}
}
