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
use Monadial\Nexus\Doctrine\Dbal\DoctrinePool;
use Monadial\Nexus\Doctrine\Dbal\Http\ConnectionResolver;
use Monadial\Nexus\Doctrine\Dbal\Http\ConnectionScopeMiddleware;
use Monadial\Nexus\Doctrine\Dbal\Http\PoolExhaustedToServiceUnavailable;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use Monadial\Nexus\Doctrine\Orm\DoctrineEmPool;
use Monadial\Nexus\Doctrine\Orm\Http\EntityManagerResolver;
use Monadial\Nexus\Doctrine\Orm\Http\EntityManagerScopeMiddleware;
use Monadial\Nexus\Doctrine\Orm\Pool\DefaultEntityManagerFactory;
use Monadial\Nexus\Doctrine\Orm\Pool\EmPoolConfig;
use Monadial\Nexus\Example\Wallet\Actor\LedgerActor;
use Monadial\Nexus\Example\Wallet\Actor\RequestActor;
use Monadial\Nexus\Example\Wallet\Actor\WalletDirectoryActor;
use Monadial\Nexus\Example\Wallet\Domain\Entity\LedgerEntry;
use Monadial\Nexus\Example\Wallet\Domain\Entity\WalletLedger;
use Monadial\Nexus\Example\Wallet\Http\Auth\DemoUsers;
use Monadial\Nexus\Example\Wallet\Http\Handler\AdminAllLedgersHandler;
use Monadial\Nexus\Example\Wallet\Http\Handler\BalanceHandler;
use Monadial\Nexus\Example\Wallet\Http\Handler\DepositHandler;
use Monadial\Nexus\Example\Wallet\Http\Handler\LedgerEntriesHandler;
use Monadial\Nexus\Example\Wallet\Http\Handler\LedgerHandler;
use Monadial\Nexus\Example\Wallet\Http\Handler\LedgerRecordHandler;
use Monadial\Nexus\Example\Wallet\Http\Handler\WithdrawHandler;
use Nyholm\Psr7\Factory\Psr17Factory;
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

/**
 * Resolve Doctrine DBAL connection params from WALLET_DB_* env vars.
 * Defaults to the Postgres service in docker-compose.yml.
 *
 * @return array<string, mixed>
 */
$resolveDbParams = static function () use ($env): array {
    return [
        'driver' => $env('WALLET_DB_DRIVER', 'pdo_pgsql'),
        'host' => $env('WALLET_DB_HOST', 'db'),
        'port' => (int) $env('WALLET_DB_PORT', '5432'),
        'dbname' => $env('WALLET_DB_NAME', 'wallet'),
        'user' => $env('WALLET_DB_USER', 'wallet'),
        'password' => $env('WALLET_DB_PASS', 'wallet'),
    ];
};

$boot->info('config resolved', [
    'host' => $host,
    'port' => $port,
    'threads' => $threads,
]);

// SWOOLE_HOOK_ALL must be installed on the MAIN thread BEFORE any worker
// threads are spawned — Swoole rejects the hook silently in child threads
// (`PHPCoroutine::enable_hook(): The runtime hook can only set on the main
// thread and no child threads have been created`). Doing this here makes
// the hook stick for every worker that the thread pool spawns.
DoctrineBootstrap::enable();

try {
    SwooleThreadServer::run(
        SwooleThreadConfig::bind($host, $port)
            ->threads($threads)
            ->shutdownTimeout(Duration::seconds(5)),
        static function (ActorSystem $system, WorkerNode $node) use ($tokensEnv, $bootstrapLogger, $resolveDbParams): CompiledApplication {
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
                // Doctrine wiring: shared Postgres-backed ledger
                // -----------------------------------------------------------------
                //
                // Connection params come from WALLET_DB_* env vars (see
                // docker-compose.yml). All worker threads point at the same
                // Postgres instance — Postgres is the single source of truth.
                //
                // SWOOLE_HOOK_ALL was enabled on the main thread above —
                // PDO calls in this worker thread already suspend the
                // coroutine on I/O. Two independent pools share the
                // Postgres connection budget:
                //   * `connPool` — DBAL `ConnectionPool` for handlers that
                //     declare `Connection $conn` (raw SQL, e.g.
                //     AdminAllLedgersHandler).
                //   * `emPool` — `EntityManagerPool` for handlers that
                //     declare `EntityManagerInterface $em` (ORM / DQL /
                //     repositories, e.g. LedgerHandler, LedgerEntriesHandler).
                //   Each pool owns its own connections. Sizes tuned
                //   independently.
                // SchemaTool::updateSchema is idempotent — runs on every
                // worker startup, but creates rows only if missing.
                // LedgerActor uses its OWN dedicated EM per actor (not
                // from any pool) — this is the EntityBehavior invariant.
                $ledgerConnParams = $resolveDbParams();

                $ormConfig = ORMSetup::createAttributeMetadataConfig(
                    paths: [dirname(__DIR__) . '/src/Domain/Entity'],
                );
                $ormConfig->enableNativeLazyObjects(true);

                // Schema bootstrap — idempotent: create if missing. Covers
                // both WalletLedger (running totals, single row per owner)
                // and LedgerEntry (one row per recorded operation).
                //
                // All worker threads run this concurrently — wrap in
                // try/catch so the winner creates and the rest no-op.
                // Postgres's pg_class lookups race on first-create.
                $bootstrapConn = DriverManager::getConnection($ledgerConnParams);
                $bootstrapEm = (new DefaultEntityManagerFactory($ormConfig))->create($bootstrapConn);
                $schemaTool = new SchemaTool($bootstrapEm);
                try {
                    $schemaTool->updateSchema([
                        $bootstrapEm->getClassMetadata(LedgerEntry::class),
                        $bootstrapEm->getClassMetadata(WalletLedger::class),
                    ]);
                } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
                    // Lost the race — another worker already created the schema. Fine.
                }
                $bootstrapEm->close();
                $bootstrapConn->close();

                $connPool = DoctrinePool::fromParams(
                    name: 'wallet-dbal',
                    connParams: $ledgerConnParams,
                    config: new PoolConfig(max: 8, minIdle: 1),
                );

                $emPool = DoctrineEmPool::forConfig(
                    name: 'wallet-em',
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

                // Wire both Doctrine pools into the HTTP pipeline. Each is
                // independent — handlers declare what they want and the
                // framework borrows lazily:
                //   - `Connection $conn`             → DBAL pool (raw SQL)
                //   - `EntityManagerInterface $em`   → EM pool (ORM / DQL)
                // PoolExhaustedToServiceUnavailable maps pool exhaustion
                // (from either pool) to HTTP 503 with Retry-After: 1.
                $app->middleware(new ConnectionScopeMiddleware($connPool));
                $app->middleware(new EntityManagerScopeMiddleware($emPool));
                $app->middleware(new PoolExhaustedToServiceUnavailable(new Psr17Factory()));
                $app->paramResolver(new ConnectionResolver());
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
                        ['method' => 'GET',  'href' => '/admin/wallets'],
                        ['method' => 'GET',  'href' => '/health'],
                        ['method' => 'GET',  'href' => '/wallet/balance'],
                        ['method' => 'POST', 'href' => '/wallet/deposit'],
                        ['method' => 'GET',  'href' => '/wallet/ledger'],
                        ['method' => 'GET',  'href' => '/wallet/ledger/entries'],
                        ['method' => 'POST', 'href' => '/wallet/ledger/record'],
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
                $app->get('/wallet/ledger/entries', LedgerEntriesHandler::class);
                // The wallet-app doesn't wire a PSR-11 container so we can't
                // use #[FromService] to inject the EntityRefFactory. Instead,
                // build the handler instance up front and register a closure
                // whose params the handler-resolver registry can introspect
                // (ServerRequestInterface + Principal here — same as the
                // handler class's __invoke signature).
                $recordHandler = new LedgerRecordHandler($ledgerFactory);
                $app->post(
                    '/wallet/ledger/record',
                    static fn(
                        \Psr\Http\Message\ServerRequestInterface $request,
                        #[\Monadial\Nexus\Http\Auth\Attribute\FromPrincipal]
                        \Monadial\Nexus\Http\Auth\Principal $principal,
                    ): ResponseInterface => $recordHandler($request, $principal),
                );

                // Admin endpoint — list all ledgers ranked by net balance.
                // Demonstrates raw SQL through the injected DBAL Connection
                // (NOT the ORM EM) plus `#[Transactional]` wrapping the
                // call in a Postgres read-only snapshot transaction.
                $app->get('/admin/wallets', AdminAllLedgersHandler::class);

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
