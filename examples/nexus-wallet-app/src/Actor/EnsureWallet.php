<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Actor;

use Monadial\Nexus\Core\Actor\Attribute\ReplyType;

#[ReplyType(WalletRef::class)]
final readonly class EnsureWallet
{
    public function __construct(public string $ownerId) {}
}
