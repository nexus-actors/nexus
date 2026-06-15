<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Domain\Command;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\Attribute\ReplyType;
use Monadial\Nexus\Example\Wallet\Domain\Money;
use Monadial\Nexus\Example\Wallet\Domain\Reply\WithdrawResult;

#[ReplyType(WithdrawResult::class)]
final readonly class Withdraw
{
    /** @param ActorRef<WithdrawResult> $replyTo */
    public function __construct(public Money $amount, public ActorRef $replyTo,) {}
}
