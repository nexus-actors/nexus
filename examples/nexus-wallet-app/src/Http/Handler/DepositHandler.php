<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Http\Handler;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Example\Wallet\Actor\HandleRequest;
use Monadial\Nexus\Example\Wallet\Domain\Reply\DepositResult;
use Monadial\Nexus\Http\Auth\Attribute\FromPrincipal;
use Monadial\Nexus\Http\Auth\Principal;
use Monadial\Nexus\Http\Handler\Attribute\FromActor;
use Monadial\Nexus\Http\Handler\Attribute\FromBody;
use Monadial\Nexus\Http\Response\JsonResponse;
use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Runtime\Duration;
use Psr\Http\Message\ResponseInterface;

/**
 * POST /wallet/deposit — body: {"amount": <cents>}. Deposits always
 * succeed when amount > 0; the WalletActor persists MoneyDeposited
 * before replying so the new balance is durable by the time the
 * handler reads it.
 */
final readonly class DepositHandler
{
    /** @param array{amount?: int} $body */
    public function __invoke(
        #[FromBody]
        array $body,
        #[FromPrincipal]
        Principal $principal,
        #[FromActor('request')]
        ActorRef $request,
        #[FromActor('wallets')]
        ActorRef $directory,
    ): ResponseInterface {
        $amount = (int) ($body['amount'] ?? 0);

        if ($amount <= 0) {
            return Response::badRequest('amount must be a positive integer');
        }

        $reply = $request
            ->ask(
                new HandleRequest(
                    ownerId: $principal->id(),
                    action: 'deposit',
                    amountCents: $amount,
                    directory: $directory,
                ),
                Duration::seconds(2),
            )
            ->await();

        assert($reply instanceof DepositResult);

        return JsonResponse::ok([
            'accepted' => $reply->accepted,
            'balance' => $reply->balanceCents,
            'ownerId' => $principal->id(),
        ]);
    }
}
