<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Domain\Event;

final readonly class MoneyWithdrawn
{
    public function __construct(public int $amountCents) {}
}
