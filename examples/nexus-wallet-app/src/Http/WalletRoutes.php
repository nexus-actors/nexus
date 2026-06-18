<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Http;

use Monadial\Nexus\Doctrine\Orm\Behavior\EntityRefFactory;
use Monadial\Nexus\Example\Wallet\Http\Handler\AdminAllLedgersHandler;
use Monadial\Nexus\Example\Wallet\Http\Handler\BalanceHandler;
use Monadial\Nexus\Example\Wallet\Http\Handler\DepositHandler;
use Monadial\Nexus\Example\Wallet\Http\Handler\IndexHandler;
use Monadial\Nexus\Example\Wallet\Http\Handler\LedgerEntriesHandler;
use Monadial\Nexus\Example\Wallet\Http\Handler\LedgerHandler;
use Monadial\Nexus\Example\Wallet\Http\Handler\LedgerRecordHandler;
use Monadial\Nexus\Example\Wallet\Http\Handler\WithdrawHandler;
use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Http\Ws\HttpApplication;
use Psr\Http\Message\ResponseInterface;

/**
 * All HTTP routes for the wallet-app, grouped by feature.
 *
 * Kept separate from the HTTP/middleware wiring so the per-worker
 * factory stays narrow and so a reader can find "what's exposed?"
 * in one place.
 */
final class WalletRoutes
{
    public static function register(
        HttpApplication $app,
        int $workerId,
        EntityRefFactory $ledgerFactory,
    ): void {
        self::meta($app, $workerId);
        self::eventSourcedWallet($app);
        self::doctrineLedger($app, $ledgerFactory);
        self::admin($app);
    }

    private static function meta(HttpApplication $app, int $workerId): void
    {
        // First-class callable syntax: `$obj(...)` returns a Closure that
        // calls the invokable's __invoke method. No `object` type in the
        // framework signature — invokables opt in at the call site.
        $index = new IndexHandler($workerId);
        $app->get('/', $index(...));
        $app->get('/health', static fn(): ResponseInterface => Response::ok());
    }

    /**
     * Original event-sourced wallet: balance / deposit / withdraw, with
     * the per-owner aggregate driven by the WalletDirectoryActor and the
     * InMemoryEventStore.
     */
    private static function eventSourcedWallet(HttpApplication $app): void
    {
        $app->get('/wallet/balance', BalanceHandler::class);
        $app->post('/wallet/deposit', DepositHandler::class);
        $app->post('/wallet/withdraw', WithdrawHandler::class);
    }

    /**
     * Doctrine ledger demo: read paths borrow an EntityManager from the pool
     * via `EntityManagerInterface $em`; the write path goes through
     * `LedgerActor` (EntityBehavior) to serialise per-owner updates.
     *
     * The POST handler is registered via PHP's first-class callable syntax
     * (`$recordHandler(...)`) so the param resolver still sees the
     * underlying `__invoke()`'s `#[FromPrincipal]` attribute through
     * reflection.
     */
    private static function doctrineLedger(HttpApplication $app, EntityRefFactory $ledgerFactory): void
    {
        $app->get('/wallet/ledger', LedgerHandler::class);
        $app->get('/wallet/ledger/entries', LedgerEntriesHandler::class);

        // First-class callable preserves the underlying __invoke()'s
        // #[FromPrincipal] attribute via reflection, so the param resolver
        // still injects the Principal correctly.
        $recordHandler = new LedgerRecordHandler($ledgerFactory);
        $app->post('/wallet/ledger/record', $recordHandler(...));
    }

    /**
     * Admin endpoint demonstrating raw DBAL + #[Transactional] (read-only
     * snapshot). Returns every ledger ranked by net balance.
     */
    private static function admin(HttpApplication $app): void
    {
        $app->get('/admin/wallets', AdminAllLedgersHandler::class);
    }
}
