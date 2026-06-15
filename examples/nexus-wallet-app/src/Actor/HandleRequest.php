<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Actor;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\Attribute\ReplyType;
use Monadial\Nexus\Example\Wallet\Domain\Reply\BalanceSnapshot;

/**
 * Tagged message sent by the HTTP handler to the per-request actor.
 * Carries the principal id, the action discriminator, and the
 * pre-injected `wallets` directory ref so the request actor doesn't
 * have to look it up itself.
 *
 * No explicit replyTo — `ask()` stamps the senderRef onto the
 * envelope, and the per-request actor replies via `$ctx->reply(...)`.
 *
 * ReplyType is annotated as BalanceSnapshot because the AskReturnType
 * provider expects a single class; the request actor union-narrows at
 * runtime to DepositResult / WithdrawResult / BalanceSnapshot.
 *
 * @psalm-suppress UnusedClass — instantiated via reflection from the HTTP handlers.
 */
#[ReplyType(BalanceSnapshot::class)]
final readonly class HandleRequest
{
    /** @param ActorRef<EnsureWallet> $directory */
    public function __construct(
        public string $ownerId,
        public string $action,
        public int $amountCents,
        public ActorRef $directory,
    ) {}
}
