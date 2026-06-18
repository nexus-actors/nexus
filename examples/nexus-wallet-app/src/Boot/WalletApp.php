<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Boot;

use Closure;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Doctrine\Dbal\Http\ConnectionResolver;
use Monadial\Nexus\Doctrine\Dbal\Http\ConnectionScopeMiddleware;
use Monadial\Nexus\Doctrine\Dbal\Http\PoolExhaustedToServiceUnavailable;
use Monadial\Nexus\Doctrine\Orm\Http\EntityManagerResolver;
use Monadial\Nexus\Doctrine\Orm\Http\EntityManagerScopeMiddleware;
use Monadial\Nexus\Example\Wallet\Actor\RequestActor;
use Monadial\Nexus\Example\Wallet\Actor\WalletDirectoryActor;
use Monadial\Nexus\Example\Wallet\Http\Auth\DemoUsers;
use Monadial\Nexus\Example\Wallet\Http\JsonExceptionRenderer;
use Monadial\Nexus\Example\Wallet\Http\WalletRoutes;
use Monadial\Nexus\Http\Auth\Middleware\AuthenticationMiddleware;
use Monadial\Nexus\Http\Auth\Resolver\FromPrincipalResolver;
use Monadial\Nexus\Http\Ws\CompiledApplication;
use Monadial\Nexus\Http\Ws\HttpApplication;
use Monadial\Nexus\Logger\Formatter\LineFormatter;
use Monadial\Nexus\Logger\Handler\ConsoleHandler;
use Monadial\Nexus\Logger\Level;
use Monadial\Nexus\Logger\NexusLogger;
use Monadial\Nexus\Persistence\Event\InMemoryEventStore;
use Monadial\Nexus\WorkerPool\WorkerNode;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Per-worker HTTP application factory.
 *
 * `WalletApp::factory($config)` returns the closure that
 * `SwooleThreadServer::run()` expects — one call per worker thread —
 * encapsulating every step a worker takes to come up:
 *
 *   1. Async NexusLogger (mailbox-backed LogActor → stderr).
 *   2. Doctrine pools + LedgerActor factory (DoctrineKit).
 *   3. HttpApplication composition: actors, middlewares, param resolvers,
 *      exception renderer, routes.
 *
 * A synchronous `StderrLogger` is kept around as a fallback for the
 * pre-actor window of worker boot and to surface a critical line if
 * the factory itself throws.
 */
final class WalletApp
{
    /**
     * @return Closure(ActorSystem, WorkerNode): CompiledApplication
     */
    public static function factory(WalletConfig $config): Closure
    {
        return static function (ActorSystem $system, WorkerNode $node) use ($config): CompiledApplication {
            $workerId = $node->workerId();
            $preBoot = StderrLogger::create("worker-{$workerId}-preactor");
            $preBoot->info('worker startup: building app');

            try {
                $log = self::asyncLogger($system, $workerId);
                $doctrine = DoctrineKit::build($config->db, $system);

                $app = HttpApplication::create($system);

                self::registerActors($app);
                self::registerMiddlewares($app, $config, $doctrine, $log);
                $renderer = new JsonExceptionRenderer($log);
                $app->onException(Throwable::class, static fn(Throwable $e) => $renderer($e));

                WalletRoutes::register($app, $workerId, $doctrine->ledgerFactory);

                $compiled = $app->compile();
                $log->info('worker startup: app compiled, accepting requests');

                return $compiled;
            } catch (Throwable $e) {
                $preBoot->critical('worker startup failed', [
                    'error' => $e::class . ': ' . $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                throw $e;
            }
        };
    }

    private static function asyncLogger(ActorSystem $system, int $workerId): LoggerInterface
    {
        // @var resource $stderr
        $stderr = STDERR;

        return NexusLogger::create($system, "worker-{$workerId}")
            ->minLevel(Level::Debug)
            ->handler(new ConsoleHandler($stderr, new LineFormatter()))
            ->build();
    }

    private static function registerActors(HttpApplication $app): void
    {
        $app->actor(
            name: 'wallets',
            props: Props::fromBehavior(WalletDirectoryActor::behavior(new InMemoryEventStore())),
        );
        $app->perRequestActor(
            name: 'request',
            props: Props::fromBehavior(RequestActor::behavior()),
        );
    }

    private static function registerMiddlewares(
        HttpApplication $app,
        WalletConfig $config,
        DoctrineKit $doctrine,
        LoggerInterface $log,
    ): void {
        // Auth: stamps `Principal` on the request from a Bearer token.
        $app->middleware(new AuthenticationMiddleware(DemoUsers::fromEnv($config->auth->tokens), $log));
        $app->paramResolver(new FromPrincipalResolver());

        // Doctrine: scope a Connection + EntityManager for the request,
        // map pool exhaustion to 503. Order matters — PoolExhausted runs
        // OUTERMOST so it can catch exhaustion exceptions thrown lazily
        // from inside handlers.
        $app->middleware(new ConnectionScopeMiddleware($doctrine->connPool));
        $app->middleware(new EntityManagerScopeMiddleware($doctrine->emPool));
        $app->middleware(new PoolExhaustedToServiceUnavailable(new Psr17Factory()));

        $app->paramResolver(new ConnectionResolver());
        $app->paramResolver(new EntityManagerResolver());
    }
}
