<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Http\Handler;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Example\Wallet\Actor\EnsureWallet;
use Monadial\Nexus\Example\Wallet\Actor\WalletRef;
use Monadial\Nexus\Example\Wallet\Domain\Command\GetBalance;
use Monadial\Nexus\Example\Wallet\Domain\Reply\BalanceSnapshot;
use Monadial\Nexus\Example\Wallet\Http\Response\BalanceResponse;
use Monadial\Nexus\Http\Auth\Attribute\FromPrincipal;
use Monadial\Nexus\Http\Auth\Principal;
use Monadial\Nexus\Http\Handler\Attribute\FromActor;
use Monadial\Nexus\Http\Response\JsonResponse;
use Monadial\Nexus\Runtime\Duration;
use Psr\Http\Message\ResponseInterface;

/**
 * GET /wallet/balance — asks the directory for the principal's wallet,
 * then asks the wallet for its current balance.
 */
final readonly class BalanceHandler
{
    public function __invoke(
        #[FromPrincipal]
        Principal $principal,
        #[FromActor('wallets')]
        ActorRef $directory,
    ): ResponseInterface {
        $walletRef = $directory
            ->ask(new EnsureWallet($principal->id()), Duration::seconds(2))
            ->await();

        assert($walletRef instanceof WalletRef);

        $reply = $walletRef->ref
            ->ask(new GetBalance(), Duration::seconds(2))
            ->await();

        assert($reply instanceof BalanceSnapshot);

        return JsonResponse::ok(new BalanceResponse(
            ownerId: $principal->id(),
            balance: $reply->balanceCents,
        ));
    }
}
