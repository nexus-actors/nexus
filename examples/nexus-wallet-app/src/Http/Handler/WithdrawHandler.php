<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Http\Handler;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Example\Wallet\Actor\EnsureWallet;
use Monadial\Nexus\Example\Wallet\Actor\HandleRequest;
use Monadial\Nexus\Example\Wallet\Actor\WalletRef;
use Monadial\Nexus\Example\Wallet\Domain\Command\Withdraw;
use Monadial\Nexus\Example\Wallet\Domain\Money;
use Monadial\Nexus\Example\Wallet\Domain\Reply\WithdrawResult;
use Monadial\Nexus\Http\Auth\Attribute\FromPrincipal;
use Monadial\Nexus\Http\Auth\Principal;
use Monadial\Nexus\Http\Handler\Attribute\FromActor;
use Monadial\Nexus\Http\Response\JsonResponse;
use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Runtime\Duration;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class WithdrawHandler
{
    public function __invoke(
        ServerRequestInterface $request,
        #[FromPrincipal]
        Principal $principal,
        #[FromActor('request')]
        ActorRef $observer,
        #[FromActor('wallets')]
        ActorRef $directory,
    ): ResponseInterface {
        /** @var array{amount?: int}|null $body */
        $body = json_decode((string) $request->getBody(), true);
        $amount = (int) ($body['amount'] ?? 0);

        if ($amount <= 0) {
            return Response::badRequest('amount must be a positive integer');
        }

        $observer->tell(new HandleRequest($principal->id(), 'withdraw', $amount));

        $walletRef = $directory
            ->ask(new EnsureWallet($principal->id()), Duration::seconds(2))
            ->await();

        assert($walletRef instanceof WalletRef);

        $reply = $walletRef->ref
            ->ask(new Withdraw(new Money($amount)), Duration::seconds(2))
            ->await();

        assert($reply instanceof WithdrawResult);

        if ($reply->accepted) {
            return JsonResponse::ok([
                'accepted' => true,
                'balance' => $reply->balanceCents,
                'ownerId' => $principal->id(),
            ]);
        }

        return new Psr7Response(
            422,
            ['content-type' => 'application/json'],
            (string) json_encode([
                'accepted' => false,
                'balance' => $reply->balanceCents,
                'ownerId' => $principal->id(),
                'reason' => $reply->rejectionReason,
            ]),
        );
    }
}
