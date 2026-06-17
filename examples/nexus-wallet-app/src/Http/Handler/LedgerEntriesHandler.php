<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Http\Handler;

use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Monadial\Nexus\Example\Wallet\Domain\Entity\LedgerEntry;
use Monadial\Nexus\Http\Auth\Attribute\FromPrincipal;
use Monadial\Nexus\Http\Auth\Principal;
use Monadial\Nexus\Http\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /wallet/ledger/entries?limit=N — last N transaction history rows
 * for the authenticated user.
 *
 * Demonstrates DQL through the pooled `EntityManagerInterface`. The EM
 * is borrowed lazily on first use and released by
 * `EntityManagerScopeMiddleware` after the response is built.
 *
 * The query uses parameter binding (not string concatenation) — DBAL +
 * PDO handle the escaping.
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
        $limit = (int) ($params['limit'] ?? 20);

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

        return JsonResponse::ok([
            'count' => count($entries),
            'entries' => array_map(
                static fn(LedgerEntry $e): array => [
                    'amountCents' => $e->amountCents,
                    'id' => $e->id,
                    'kind' => $e->kind,
                    'occurredAt' => $e->occurredAt->format(DateTimeInterface::ATOM),
                ],
                $entries,
            ),
            'ownerId' => $principal->id(),
        ]);
    }
}
