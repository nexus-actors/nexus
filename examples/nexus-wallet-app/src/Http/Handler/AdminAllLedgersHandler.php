<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Http\Handler;

use Doctrine\DBAL\Connection;
use Monadial\Nexus\Doctrine\Dbal\Http\Attribute\Transactional;
use Monadial\Nexus\Example\Wallet\Http\Response\AdminWalletsResponse;
use Monadial\Nexus\Example\Wallet\Http\Response\AdminWalletSummary;
use Monadial\Nexus\Http\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;

use function array_map;
use function count;

/**
 * GET /admin/wallets — list every wallet ledger with running totals,
 * via raw SQL through the injected DBAL `Connection`.
 *
 * This is the OTHER handler-injection pattern: when you want hand-rolled
 * SQL with no ORM overhead, type-hint `Connection` instead of
 * `EntityManagerInterface`. The framework pulls one from the DBAL
 * `ConnectionPool` (NOT the EM pool — these are separate pools sharing
 * the same Postgres but independently sized).
 *
 * `#[Transactional]` here is mostly demonstrative on a read-only handler
 * — Postgres will use a read-only snapshot inside the txn. For actual
 * write handlers, `#[Transactional]` wraps the call in
 * `Connection::beginTransaction()` / `commit()` / `rollBack()`.
 */
#[Transactional]
final readonly class AdminAllLedgersHandler
{
    public function __invoke(Connection $conn): ResponseInterface
    {
        // Postgres folds unquoted identifiers to lowercase, so the
        // camelCase Doctrine property names ($ownerId, $depositCount, …)
        // become `ownerid`, `depositcount`, etc. when DDL runs without
        // an underscore naming strategy.
        /** @var list<array{ownerid: string, depositcount: int, withdrawcount: int, depositcents: int, withdrawcents: int, lastactivityat: ?string}> $rows */
        $rows = $conn->fetchAllAssociative(
            'SELECT ownerid, depositcount, withdrawcount,
                    depositcents, withdrawcents, lastactivityat
             FROM wallet_ledgers
             ORDER BY depositcents - withdrawcents DESC
             LIMIT 100',
        );

        return JsonResponse::ok(new AdminWalletsResponse(
            count: count($rows),
            wallets: array_map(
                static fn(array $r): AdminWalletSummary => new AdminWalletSummary(
                    ownerId: $r['ownerid'],
                    depositCents: $r['depositcents'],
                    depositCount: $r['depositcount'],
                    withdrawCents: $r['withdrawcents'],
                    withdrawCount: $r['withdrawcount'],
                    netCents: $r['depositcents'] - $r['withdrawcents'],
                    lastActivityAt: $r['lastactivityat'],
                ),
                $rows,
            ),
        ));
    }
}
