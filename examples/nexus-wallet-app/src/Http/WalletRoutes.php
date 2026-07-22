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
use Monadial\Nexus\Example\Wallet\Http\Response\IndexLink;
use Monadial\Nexus\Example\Wallet\Http\Response\IndexResponse;
use Monadial\Nexus\Http\Auth\Middleware\AuthorizationMiddleware;
use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Http\Routing\RouteSummary;
use Monadial\Nexus\Http\Ws\HttpApplication;
use Psr\Http\Message\ResponseInterface;

use function array_map;

/**
 * All HTTP routes for the wallet-app, grouped by feature.
 *
 * Kept separate from the HTTP/middleware wiring so the per-worker
 * factory stays narrow and so a reader can find "what's exposed?"
 * in one place.
 */
final class WalletRoutes
{
    public static function register(HttpApplication $app, int $workerId, EntityRefFactory $ledgerFactory): void
    {
        $app->get('/health', static fn(): ResponseInterface => Response::ok());
        self::eventSourcedWallet($app);
        self::doctrineLedger($app, $ledgerFactory);
        self::admin($app);

        // Register the index LAST so the link map covers every other route
        // that the framework already knows about (snapshot taken now via
        // $app->registeredRoutes()).
        $app->get('/', new IndexHandler(self::index($app, $workerId))(...));
    }

    private static function index(HttpApplication $app, int $workerId): IndexResponse
    {
        $links = array_map(
            static fn(RouteSummary $r): IndexLink => new IndexLink($r->method, $r->path),
            $app->registeredRoutes(),
        );

        return new IndexResponse(name: 'nexus-wallet-app', thread: $workerId, links: $links);
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
     */
    private static function doctrineLedger(HttpApplication $app, EntityRefFactory $ledgerFactory): void
    {
        $app->get('/wallet/ledger', LedgerHandler::class);
        $app->get('/wallet/ledger/entries', LedgerEntriesHandler::class);

        // First-class callable preserves the underlying __invoke()'s
        // #[FromPrincipal] attribute via reflection.
        $app->post('/wallet/ledger/record', new LedgerRecordHandler($ledgerFactory)(...));
    }

    /**
     * Admin endpoint demonstrating raw DBAL + #[Transactional] (read-only
     * snapshot). Returns every ledger ranked by net balance.
     *
     * Requires the `admin` role: the handler is annotated #[RequiresRole('admin')]
     * and the route carries AuthorizationMiddleware, so an anonymous or
     * non-admin caller is rejected (401/403) before the query runs. Compilation
     * fails closed if the enforcer is ever removed (SEC-003).
     */
    private static function admin(HttpApplication $app): void
    {
        $app->get('/admin/wallets', AdminAllLedgersHandler::class)
            ->middleware(AuthorizationMiddleware::class);
    }
}
