<?php

declare(strict_types=1);

/**
 * Wallet-app entry point.
 *
 * Boots a multi-thread Swoole HTTP server via SwooleThreadServer::run.
 * Each thread builds an independent ActorSystem and registers:
 *
 *   1. WalletDirectoryActor (long-lived)  — supervises per-owner wallets.
 *   2. RequestActor          (per-request) — orchestrates each HTTP call.
 *   3. Authentication middleware           — stamps Principal on request.
 *   4. Three invokable handlers             — Balance / Deposit / Withdraw.
 *
 * Per-thread isolation is intentional: each worker has its own copy of
 * the directory + in-memory event store. For a single source of truth
 * across threads, swap InMemoryEventStore for a shared store
 * (DbalEventStore against Postgres, etc.).
 */
require_once dirname(__DIR__) . '/vendor/autoload.php';

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Example\Wallet\Actor\RequestActor;
use Monadial\Nexus\Example\Wallet\Actor\WalletDirectoryActor;
use Monadial\Nexus\Example\Wallet\Http\Auth\DemoUsers;
use Monadial\Nexus\Example\Wallet\Http\Handler\BalanceHandler;
use Monadial\Nexus\Example\Wallet\Http\Handler\DepositHandler;
use Monadial\Nexus\Example\Wallet\Http\Handler\WithdrawHandler;
use Monadial\Nexus\Http\Auth\Middleware\AuthenticationMiddleware;
use Monadial\Nexus\Http\Auth\Resolver\FromPrincipalResolver;
use Monadial\Nexus\Http\Response\JsonResponse;
use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Http\Server\Swoole\Threads\Server\SwooleThreadConfig;
use Monadial\Nexus\Http\Server\Swoole\Threads\Server\SwooleThreadServer;
use Monadial\Nexus\Http\Ws\CompiledApplication;
use Monadial\Nexus\Http\Ws\HttpApplication;
use Monadial\Nexus\Persistence\Event\InMemoryEventStore;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\WorkerPool\WorkerNode;

/** @return non-empty-string */
$env = static function (string $name, string $default): string {
    $value = getenv($name);

    return $value === false || $value === ''
        ? $default
        : $value;
};

$host = $env('WALLET_HTTP_HOST', '0.0.0.0');
$port = (int) $env('WALLET_HTTP_PORT', '8080');
$threads = (int) $env('WALLET_THREADS', '4');
$tokensEnv = $env('WALLET_AUTH_TOKENS', 'alice-token=alice,bob-token=bob,carol-token=carol');

SwooleThreadServer::run(
    SwooleThreadConfig::bind($host, $port)
        ->threads($threads)
        ->shutdownTimeout(Duration::seconds(5)),
    static function (ActorSystem $system, WorkerNode $node) use ($tokensEnv): CompiledApplication {
        // Per-thread in-memory store — see file header for swap-out notes.
        $eventStore = new InMemoryEventStore();

        $app = HttpApplication::create($system);

        // Long-lived router → fans out to per-owner WalletActors. Handlers
        // reach it via #[FromActor('wallets')] — no need for a ref-getter
        // on ActorRegistration.
        $app->actor(
            name: 'wallets',
            props: Props::fromBehavior(WalletDirectoryActor::behavior($eventStore)),
        );

        // Per-request actor → freshly spawned for every inbound HTTP call,
        // stopped after the response is written. Stateless at construction
        // — the directory ref arrives via the HandleRequest message.
        $app->perRequestActor(
            name: 'request',
            props: Props::fromBehavior(RequestActor::behavior()),
        );

        // Bearer-token auth: AuthenticationMiddleware stamps the Principal
        // onto the PSR-7 request as the `principal` attribute;
        // FromPrincipalResolver hands it to handler parameters.
        $authenticator = DemoUsers::fromEnv($tokensEnv);
        $app->middleware(new AuthenticationMiddleware($authenticator));
        $app->paramResolver(new FromPrincipalResolver());

        // Public endpoints — no auth required.
        $app->get('/', static fn(): mixed => JsonResponse::ok([
            'name' => 'nexus-wallet-app',
            'thread' => $node->workerId(),
            'links' => [
                ['method' => 'GET',  'href' => '/health'],
                ['method' => 'GET',  'href' => '/wallet/balance'],
                ['method' => 'POST', 'href' => '/wallet/deposit'],
                ['method' => 'POST', 'href' => '/wallet/withdraw'],
            ],
        ]));
        $app->get('/health', static fn(): mixed => Response::ok());

        // Wallet routes — each handler injects FromBody + FromPrincipal +
        // FromActor('request') + FromActor('wallets') via attributes.
        $app->get('/wallet/balance', BalanceHandler::class);
        $app->post('/wallet/deposit', DepositHandler::class);
        $app->post('/wallet/withdraw', WithdrawHandler::class);

        return $app->compile();
    },
);
