<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Http\Handler;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Example\Wallet\Actor\HandleRequest;
use Monadial\Nexus\Example\Wallet\Domain\Reply\BalanceSnapshot;
use Monadial\Nexus\Http\Auth\Attribute\FromPrincipal;
use Monadial\Nexus\Http\Auth\Principal;
use Monadial\Nexus\Http\Handler\Attribute\FromActor;
use Monadial\Nexus\Http\Response\JsonResponse;
use Monadial\Nexus\Runtime\Duration;
use Psr\Http\Message\ResponseInterface;

/**
 * GET /wallet/balance — asks the per-request actor, which forwards to
 * the principal's wallet via the directory, and projects the snapshot
 * back to JSON.
 */
final readonly class BalanceHandler
{
    public function __invoke(
        #[FromPrincipal]
        Principal $principal,
        #[FromActor('request')]
        ActorRef $request,
        #[FromActor('wallets')]
        ActorRef $directory,
    ): ResponseInterface {
        $reply = $request
            ->ask(
                new HandleRequest(
                    ownerId: $principal->id(),
                    action: 'balance',
                    amountCents: 0,
                    directory: $directory,
                ),
                Duration::seconds(2),
            )
            ->await();

        assert($reply instanceof BalanceSnapshot);

        return JsonResponse::ok([
            'balance' => $reply->balanceCents,
            'ownerId' => $principal->id(),
        ]);
    }
}
