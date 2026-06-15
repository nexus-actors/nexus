<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Actor;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\Attribute\ReplyType;
use Monadial\Nexus\Example\Wallet\Domain\Reply\BalanceSnapshot;
use Monadial\Nexus\Example\Wallet\Domain\Reply\DepositResult;
use Monadial\Nexus\Example\Wallet\Domain\Reply\WithdrawResult;

/**
 * Tagged message sent by the HTTP handler to the per-request actor.
 * Carries the principal id, the domain command (without its replyTo —
 * the request actor allocates that), and where to send the final reply.
 *
 * The reply union is widened via @psalm-type because the response shape
 * depends on the command type.
 */
#[ReplyType(BalanceSnapshot::class)]
final readonly class HandleRequest
{
    /**
     * @param ActorRef<EnsureWallet> $directory
     * @param ActorRef<BalanceSnapshot|DepositResult|WithdrawResult> $replyTo
     */
    public function __construct(
        public string $ownerId,
        public string $action,
        public int $amountCents,
        public ActorRef $directory,
        public ActorRef $replyTo,
    ) {}
}
