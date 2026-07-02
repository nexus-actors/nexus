<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Domain\Command;

use Monadial\Nexus\Core\Actor\Attribute\ReplyType;
use Monadial\Nexus\Example\Wallet\Domain\Money;
use Monadial\Nexus\Example\Wallet\Domain\Reply\DepositResult;

#[ReplyType(DepositResult::class)]
final readonly class Deposit
{
    public function __construct(public Money $amount) {}
}
