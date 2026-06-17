<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Http\Handler;

use Doctrine\DBAL\Connection;
use Monadial\Nexus\Doctrine\Dbal\Http\Attribute\Transactional;
use Monadial\Nexus\Http\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;

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
        /** @var list<array{owner_id: string, deposit_count: int, withdraw_count: int, deposit_cents: int, withdraw_cents: int, last_activity_at: ?string}> $rows */
        $rows = $conn->fetchAllAssociative(
            'SELECT owner_id, deposit_count, withdraw_count,
                    deposit_cents, withdraw_cents, last_activity_at
             FROM wallet_ledgers
             ORDER BY deposit_cents - withdraw_cents DESC
             LIMIT 100',
        );

        return JsonResponse::ok([
            'count' => count($rows),
            'wallets' => array_map(
                static fn(array $r): array => [
                    'depositCents' => (int) $r['deposit_cents'],
                    'depositCount' => (int) $r['deposit_count'],
                    'lastActivityAt' => $r['last_activity_at'],
                    'netCents' => (int) $r['deposit_cents'] - (int) $r['withdraw_cents'],
                    'ownerId' => (string) $r['owner_id'],
                    'withdrawCents' => (int) $r['withdraw_cents'],
                    'withdrawCount' => (int) $r['withdraw_count'],
                ],
                $rows,
            ),
        ]);
    }
}
