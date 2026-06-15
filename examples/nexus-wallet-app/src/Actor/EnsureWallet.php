<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Actor;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\Attribute\ReplyType;

#[ReplyType(WalletRef::class)]
final readonly class EnsureWallet
{
    /** @param ActorRef<WalletRef> $replyTo */
    public function __construct(public string $ownerId, public ActorRef $replyTo,) {}
}
