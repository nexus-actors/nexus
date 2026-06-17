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
 *
 * Logging: a Monolog → STDERR pre-boot logger captures crashes before
 * any actor system exists; once each worker thread boots, the async
 * NexusLogger (mailbox-backed LogActor) takes over and pushes records
 * to stderr via the ConsoleHandler. `docker compose logs app` surfaces
 * both.
 */
require_once dirname(__DIR__) . '/vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Doctrine\Dbal\Bootstrap\DoctrineBootstrap;
use Monadial\Nexus\Doctrine\Orm\DoctrineEmPool;
use Monadial\Nexus\Doctrine\Orm\Http\EntityManagerResolver;
use Monadial\Nexus\Doctrine\Orm\Http\EntityManagerScopeMiddleware;
use Monadial\Nexus\Doctrine\Orm\Pool\DefaultEntityManagerFactory;
use Monadial\Nexus\Doctrine\Orm\Pool\EmPoolConfig;
use Monadial\Nexus\Example\Wallet\Actor\LedgerActor;
use Monadial\Nexus\Example\Wallet\Actor\RequestActor;
use Monadial\Nexus\Example\Wallet\Actor\WalletDirectoryActor;
use Monadial\Nexus\Example\Wallet\Domain\Entity\WalletLedger;
use Monadial\Nexus\Example\Wallet\Http\Auth\DemoUsers;
use Monadial\Nexus\Example\Wallet\Http\Handler\BalanceHandler;
use Monadial\Nexus\Example\Wallet\Http\Handler\DepositHandler;
use Monadial\Nexus\Example\Wallet\Http\Handler\LedgerHandler;
use Monadial\Nexus\Example\Wallet\Http\Handler\LedgerRecordHandler;
use Monadial\Nexus\Example\Wallet\Http\Handler\WithdrawHandler;
use Monadial\Nexus\Http\Auth\Middleware\AuthenticationMiddleware;
use Monadial\Nexus\Http\Auth\Resolver\FromPrincipalResolver;
use Monadial\Nexus\Http\Response\JsonResponse;
use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Http\Server\Swoole\Threads\Server\SwooleThreadConfig;
use Monadial\Nexus\Http\Server\Swoole\Threads\Server\SwooleThreadServer;
use Monadial\Nexus\Http\Ws\CompiledApplication;
use Monadial\Nexus\Http\Ws\HttpApplication;
use Monadial\Nexus\Logger\Formatter\LineFormatter;
use Monadial\Nexus\Logger\Handler\ConsoleHandler;
use Monadial\Nexus\Logger\Level;
use Monadial\Nexus\Logger\NexusLogger;
use Monadial\Nexus\Persistence\Event\InMemoryEventStore;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\WorkerPool\WorkerNode;
use Monolog\Formatter\LineFormatter as MonologLineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level as MonologLevel;
use Monolog\Logger as MonologLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/** @return non-empty-string */
$env = static function (string $name, string $default): string {
    $value = getenv($name);

    return $value === false || $value === ''
        ? $default
        : $value;
};

/**
 * Pre-boot Monolog → stderr — used ONLY during bootstrap, before the
 * actor system exists. After each worker boots its own ActorSystem we
 * switch to the async NexusLogger.
 */
$bootstrapLogger = static function (string $channel): LoggerInterface {
    $handler = new StreamHandler('php://stderr', MonologLevel::Debug);
    $handler->setFormatter(new MonologLineFormatter(
        format: "[%datetime%] %channel%.%level_name%: %message% %context%\n",
        dateFormat: 'Y-m-d H:i:s.u',
        allowInlineLineBreaks: true,
        ignoreEmptyContextAndExtra: true,
    ));

    return (new MonologLogger($channel))->pushHandler($handler);
};

$boot = $bootstrapLogger('bootstrap');
$boot->info('booting nexus-wallet-app', [
    'php' => PHP_VERSION,
    'pid' => getmypid(),
    'swoole' => phpversion('swoole'),
]);

$host = $env('WALLET_HTTP_HOST', '0.0.0.0');
$port = (int) $env('WALLET_HTTP_PORT', '8080');
$threads = (int) $env('WALLET_THREADS', '4');
$tokensEnv = $env('WALLET_AUTH_TOKENS', 'alice-token=alice,bob-token=bob,carol-token=carol');

$boot->info('config resolved', [
    'host' => $host,
    'port' => $port,
    'threads' => $threads,
]);

try {
    SwooleThreadServer::run(
        SwooleThreadConfig::bind($host, $port)
            ->threads($threads)
            ->shutdownTimeout(Duration::seconds(5)),
        static function (ActorSystem $system, WorkerNode $node) use ($tokensEnv, $bootstrapLogger): CompiledApplication {
            $workerId = (string) $node->workerId();

            // Async NexusLogger: records flow into a mailbox-backed
            // LogActor and out to stderr via ConsoleHandler. Doesn't
            // block the request path.
            //
            // @var resource $stderr
            $stderr = STDERR;
            $log = NexusLogger::create($system, 'worker-' . $workerId)
                ->minLevel(Level::Debug)
                ->handler(new ConsoleHandler($stderr, new LineFormatter()))
                ->build();

            // Fallback for the moments BEFORE the LogActor mailbox is
            // ready (the very first ticks of system setup). Same stderr,
            // synchronous.
            $preActorLog = $bootstrapLogger('worker-' . $workerId . '-preactor');
            $preActorLog->info('worker startup: building app');

            try {
                $eventStore = new InMemoryEventStore();

                // -----------------------------------------------------------------
                // Doctrine wiring: per-worker SQLite-backed ledger
                // -----------------------------------------------------------------
                //
                // The ledger is a denormalised read model (per-owner running totals)
                // that lives in SQLite at `WALLET_LEDGER_DB` (default `/tmp/wallet-ledger-{worker}.db`).
                // Each worker thread has its own DB file — this is a demo, not a
                // shared write target. Production would point at a single Postgres
                // and let the EM pool handle concurrent borrows.
                //
                // - DoctrineBootstrap::enable() flips SWOOLE_HOOK_ALL so the EM
                //   pool's PDO connections suspend the coroutine on I/O.
                // - DoctrineEmPool::forConfig() builds the pool + internal
                //   ConnectionPool + DefaultEntityManagerFactory.
                // - SchemaTool creates `wallet_ledgers` once per worker startup.
                // - The pool is wired into HTTP via EntityManagerScopeMiddleware +
                //   EntityManagerResolver (handler param `EntityManagerInterface $em`
                //   is borrowed lazily, released at response).
                // - LedgerActor::factory() builds an EntityRefFactory — one
                //   LedgerActor per owner, single writer.
                DoctrineBootstrap::enable();

                $ledgerDbEnv = getenv('WALLET_LEDGER_DB');
                $ledgerDbPath = $ledgerDbEnv === false || $ledgerDbEnv === ''
                    ? '/tmp/wallet-ledger-' . $workerId . '.db'
                    : $ledgerDbEnv;
                $ledgerConnParams = ['driver' => 'pdo_sqlite', 'path' => $ledgerDbPath];

                $ormConfig = ORMSetup::createAttributeMetadataConfig(
                    paths: [dirname(__DIR__) . '/src/Domain/Entity'],
                );
                $ormConfig->enableNativeLazyObjects(true);

                // Schema bootstrap — idempotent: create if missing
                $bootstrapConn = DriverManager::getConnection($ledgerConnParams);
                $bootstrapEm = (new DefaultEntityManagerFactory($ormConfig))->create($bootstrapConn);
                $schemaTool = new SchemaTool($bootstrapEm);
                $schemaTool->updateSchema([$bootstrapEm->getClassMetadata(WalletLedger::class)]);
                $bootstrapEm->close();
                $bootstrapConn->close();

                $emPool = DoctrineEmPool::forConfig(
                    name: 'wallet-ledger',
                    connParams: $ledgerConnParams,
                    ormSetup: $ormConfig,
                    config: new EmPoolConfig(max: 8, minIdle: 1),
                );

                $ledgerFactory = LedgerActor::factory($system, $ormConfig, $ledgerConnParams);

                $app = HttpApplication::create($system);

                $app->actor(
                    name: 'wallets',
                    props: Props::fromBehavior(WalletDirectoryActor::behavior($eventStore)),
                );

                $app->perRequestActor(
                    name: 'request',
                    props: Props::fromBehavior(RequestActor::behavior()),
                );

                $authenticator = DemoUsers::fromEnv($tokensEnv);
                $app->middleware(new AuthenticationMiddleware($authenticator, $log));
                $app->paramResolver(new FromPrincipalResolver());

                // Wire the EM pool into the HTTP pipeline. Handlers that declare an
                // `EntityManagerInterface $em` param (see LedgerHandler) borrow lazily
                // on first use; the middleware releases on response.
                $app->middleware(new EntityManagerScopeMiddleware($emPool));
                $app->paramResolver(new EntityManagerResolver());

                // Surface every uncaught handler exception to stderr — the
                // framework's default catch-all returns 500 with no log line.
                $app->onException(Throwable::class, static function (Throwable $e) use ($log): ResponseInterface {
                    $log->error('handler exception', [
                        'class' => $e::class,
                        'message' => $e->getMessage(),
                        'file' => $e->getFile() . ':' . $e->getLine(),
                        'trace' => $e->getTraceAsString(),
                    ]);

                    return new \Nyholm\Psr7\Response(
                        500,
                        ['content-type' => 'application/json'],
                        (string) json_encode([
                            'error' => $e::class,
                            'message' => $e->getMessage(),
                        ]),
                    );
                });

                $app->get('/', static fn(): mixed => JsonResponse::ok([
                    'links' => [
                        ['method' => 'GET',  'href' => '/health'],
                        ['method' => 'GET',  'href' => '/wallet/balance'],
                        ['method' => 'POST', 'href' => '/wallet/deposit'],
                        ['method' => 'POST', 'href' => '/wallet/withdraw'],
                    ],
                    'name' => 'nexus-wallet-app',
                    'thread' => $node->workerId(),
                ]));
                $app->get('/health', static fn(): mixed => Response::ok());

                $app->get('/wallet/balance', BalanceHandler::class);
                $app->post('/wallet/deposit', DepositHandler::class);
                $app->post('/wallet/withdraw', WithdrawHandler::class);

                // Doctrine ledger — denormalised read model + EntityBehavior writer.
                // The GET handler receives an `EntityManagerInterface` from the pool.
                // The POST handler captures the EntityRefFactory via constructor
                // (no PSR-11 container is wired in this example, so we register
                // it as an instance-binding closure).
                $app->get('/wallet/ledger', LedgerHandler::class);
                $recordHandler = new LedgerRecordHandler($ledgerFactory);
                $app->post('/wallet/ledger/record', static fn(...$args): ResponseInterface => $recordHandler(...$args));

                $compiled = $app->compile();
                $log->info('worker startup: app compiled, accepting requests');

                return $compiled;
            } catch (Throwable $e) {
                // Use the synchronous preActorLog — if the async logger's
                // mailbox/LogActor is what crashed, NexusLogger would
                // re-throw inside this catch.
                $preActorLog->critical('worker startup failed', [
                    'error' => $e::class . ': ' . $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                throw $e;
            }
        },
    );
} catch (Throwable $e) {
    $boot->critical('server crashed', [
        'error' => $e::class . ': ' . $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);

    exit(1);
}
