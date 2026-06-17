<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Http\Handler;

use Doctrine\ORM\EntityManagerInterface;
use Monadial\Nexus\Example\Wallet\Domain\Entity\WalletLedger;
use Monadial\Nexus\Http\Auth\Attribute\FromPrincipal;
use Monadial\Nexus\Http\Auth\Principal;
use Monadial\Nexus\Http\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;

/**
 * GET /wallet/ledger — read the authenticated user's denormalised ledger.
 *
 * Demonstrates pooled `EntityManagerInterface` injection: the framework
 * borrows an EM from the `EntityManagerPool` on first `$em->find()` call,
 * releases it (and clears the UoW) at the end of the request.
 *
 * No actor involvement on the read path — direct EM query is fast and
 * single-shot, and reading via the writer actor would be wasted ceremony.
 */
final readonly class LedgerHandler
{
    public function __invoke(
        #[FromPrincipal]
        Principal $principal,
        EntityManagerInterface $em,
    ): ResponseInterface {
        $ledger = $em->find(WalletLedger::class, $principal->id());

        if ($ledger === null) {
            return JsonResponse::ok([
                'depositCents' => 0,
                'depositCount' => 0,
                'lastActivityAt' => null,
                'ownerId' => $principal->id(),
                'withdrawCents' => 0,
                'withdrawCount' => 0,
            ]);
        }

        return JsonResponse::ok([
            'depositCents' => $ledger->depositCents,
            'depositCount' => $ledger->depositCount,
            'lastActivityAt' => $ledger->lastActivityAt?->format(\DateTimeInterface::ATOM),
            'ownerId' => $ledger->ownerId,
            'withdrawCents' => $ledger->withdrawCents,
            'withdrawCount' => $ledger->withdrawCount,
        ]);
    }
}
