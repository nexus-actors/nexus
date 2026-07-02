<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Http\Handler;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Example\Wallet\Actor\EnsureWallet;
use Monadial\Nexus\Example\Wallet\Actor\WalletRef;
use Monadial\Nexus\Example\Wallet\Domain\Command\Deposit;
use Monadial\Nexus\Example\Wallet\Domain\Money;
use Monadial\Nexus\Example\Wallet\Domain\Reply\DepositResult;
use Monadial\Nexus\Example\Wallet\Http\Request\AmountRequest;
use Monadial\Nexus\Example\Wallet\Http\Response\WalletOperationResponse;
use Monadial\Nexus\Http\Auth\Attribute\FromPrincipal;
use Monadial\Nexus\Http\Auth\Principal;
use Monadial\Nexus\Http\Handler\Attribute\FromActor;
use Monadial\Nexus\Http\Handler\Attribute\FromBody;
use Monadial\Nexus\Http\Response\JsonResponse;
use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Runtime\Duration;
use Psr\Http\Message\ResponseInterface;

final readonly class DepositHandler
{
    public function __invoke(
        #[FromPrincipal]
        Principal $principal,
        #[FromBody]
        AmountRequest $body,
        #[FromActor('wallets')]
        ActorRef $directory,
    ): ResponseInterface {
        if ($body->amountCents <= 0) {
            return Response::badRequest('amountCents must be a positive integer');
        }

        $walletRef = $directory
            ->ask(new EnsureWallet($principal->id()), Duration::seconds(2))
            ->await();

        assert($walletRef instanceof WalletRef);

        $reply = $walletRef->ref
            ->ask(new Deposit(new Money($body->amountCents)), Duration::seconds(2))
            ->await();

        assert($reply instanceof DepositResult);

        return JsonResponse::ok(new WalletOperationResponse(
            ownerId: $principal->id(),
            accepted: $reply->accepted,
            balance: $reply->balanceCents,
        ));
    }
}
