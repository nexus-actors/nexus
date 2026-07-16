<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Http\Handler;

use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Monadial\Nexus\Example\Wallet\Domain\Entity\LedgerEntry;
use Monadial\Nexus\Example\Wallet\Http\Response\LedgerEntriesResponse;
use Monadial\Nexus\Example\Wallet\Http\Response\LedgerEntryResponse;
use Monadial\Nexus\Http\Auth\Attribute\FromPrincipal;
use Monadial\Nexus\Http\Auth\Principal;
use Monadial\Nexus\Http\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function array_map;
use function count;
use function is_numeric;

/**
 * GET /wallet/ledger/entries?limit=N — last N transaction history rows
 * for the authenticated user.
 *
 * Demonstrates DQL through the pooled `EntityManagerInterface`. The EM
 * is borrowed lazily on first use and released by
 * `EntityManagerScopeMiddleware` after the response is built.
 */
final readonly class LedgerEntriesHandler
{
    public function __invoke(
        ServerRequestInterface $request,
        #[FromPrincipal]
        Principal $principal,
        EntityManagerInterface $em,
    ): ResponseInterface {
        $params = $request->getQueryParams();
        // Query param shape is mixed by the PSR-7 contract — accept numeric
        // input only, anything else falls back to the default.
        $limit = isset($params['limit']) && is_numeric($params['limit'])
            ? (int) $params['limit']
            : 20;

        if ($limit < 1 || $limit > 200) {
            $limit = 20;
        }

        $query = $em->createQuery(
            'SELECT e FROM ' . LedgerEntry::class . ' e
             WHERE e.ledger = :ownerId
             ORDER BY e.occurredAt DESC',
        );
        $query->setParameter('ownerId', $principal->id());
        $query->setMaxResults($limit);

        /** @var list<LedgerEntry> $entries */
        $entries = $query->getResult();

        return JsonResponse::ok(new LedgerEntriesResponse(
            ownerId: $principal->id(),
            count: count($entries),
            entries: array_map(
                static fn(LedgerEntry $e): LedgerEntryResponse => new LedgerEntryResponse(
                    id: $e->id,
                    kind: $e->kind,
                    amountCents: $e->amountCents,
                    occurredAt: $e->occurredAt->format(DateTimeInterface::ATOM),
                ),
                $entries,
            ),
        ));
    }
}
