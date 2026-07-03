<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Domain\Command;

use Monadial\Nexus\Core\Actor\Attribute\ReplyType;
use Monadial\Nexus\Example\Wallet\Domain\Reply\BalanceSnapshot;

#[ReplyType(BalanceSnapshot::class)]
final readonly class GetBalance implements WalletCommand {}
