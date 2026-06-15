<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Http\Handler;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Example\Wallet\Actor\HandleRequest;
use Monadial\Nexus\Example\Wallet\Domain\Reply\WithdrawResult;
use Monadial\Nexus\Http\Auth\Attribute\FromPrincipal;
use Monadial\Nexus\Http\Auth\Principal;
use Monadial\Nexus\Http\Handler\Attribute\FromActor;
use Monadial\Nexus\Http\Handler\Attribute\FromBody;
use Monadial\Nexus\Http\Response\JsonResponse;
use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Runtime\Duration;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Message\ResponseInterface;

/**
 * POST /wallet/withdraw — body: {"amount": <cents>}. The WalletActor
 * checks the balance before persisting MoneyWithdrawn. On insufficient
 * funds we reply 422 with the rejection reason; the event log stays
 * unchanged. This is the canonical "decide-then-persist" pattern: the
 * decision is part of the event, not the command.
 */
final readonly class WithdrawHandler
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

        $reply = $request->ask(
            static fn(ActorRef $rt): HandleRequest => new HandleRequest(
                ownerId: $principal->id(),
                action: 'withdraw',
                amountCents: $amount,
                directory: $directory,
                replyTo: $rt,
            ),
            Duration::seconds(2),
        )->await();

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
