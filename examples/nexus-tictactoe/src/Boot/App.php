<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Boot;

use Closure;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Doctrine\Dbal\Http\ConnectionResolver;
use Monadial\Nexus\Doctrine\Dbal\Http\ConnectionScopeMiddleware;
use Monadial\Nexus\Doctrine\Dbal\Http\PoolExhaustedToServiceUnavailable;
use Monadial\Nexus\Doctrine\Orm\Http\EntityManagerResolver;
use Monadial\Nexus\Doctrine\Orm\Http\EntityManagerScopeMiddleware;
use Monadial\Nexus\Example\TicTacToe\Http\Handler\IndexHandler;
use Monadial\Nexus\Example\TicTacToe\Http\JsonExceptionRenderer;
use Monadial\Nexus\Example\TicTacToe\Http\Routes;
use Monadial\Nexus\Http\Ws\CompiledApplication;
use Monadial\Nexus\Http\Ws\WsApplication;
use Monadial\Nexus\Logger\Formatter\LineFormatter;
use Monadial\Nexus\Logger\Handler\ConsoleHandler;
use Monadial\Nexus\Logger\Level;
use Monadial\Nexus\Logger\NexusLogger;
use Monadial\Nexus\Serialization\Exception\MessageDeserializationException;
use Monadial\Nexus\Serialization\ValinorJsonSerializer;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Log\LoggerInterface;
use Throwable;

use function json_encode;

/**
 * Per-worker application factory.
 *
 * Every collaborator (serializer, index handler, codec) is constructed
 * once at boot and injected; nothing is resolved from static context or
 * built per-request.
 */
final class App
{
    /**
     * @return Closure(ActorSystem): CompiledApplication
     */
    public static function factory(Config $config): Closure
    {
        return static function (ActorSystem $system) use ($config): CompiledApplication {
            $pid = getmypid();
            $workerId = $pid !== false
                ? $pid
                : 0;
            $preBoot = StderrLogger::create("worker-{$workerId}-preactor");
            $preBoot->info('worker startup: building app');

            try {
                $log = self::asyncLogger($system, $workerId);
                $doctrine = DoctrineKit::build($config->db, $system, $log);
                $serializer = new ValinorJsonSerializer();
                $indexHandler = new IndexHandler(dirname(__DIR__, 2) . '/public/dist/index.html');

                $app = WsApplication::create($system);
                $app->withMessageSerializer($serializer);
                // Wire the async NexusLogger into the framework — otherwise
                // WebSocketDispatcher / ChannelActorRegistry / etc. write to
                // a NullLogger by default and you never see WS events.
                $app->withLogger($log);

                self::registerMiddlewares($app, $doctrine);
                self::registerExceptionMappers($app, $log);

                Routes::register($app, $doctrine->gameFactory, $serializer, $indexHandler, $log);

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
        $stderr = STDERR;

        return NexusLogger::create($system, "worker-{$workerId}")
            ->minLevel(Level::Debug)
            ->handler(new ConsoleHandler($stderr, new LineFormatter()))
            ->build();
    }

    private static function registerMiddlewares(WsApplication $app, DoctrineKit $doctrine): void
    {
        $app->middleware(new ConnectionScopeMiddleware($doctrine->connPool));
        $app->middleware(new EntityManagerScopeMiddleware($doctrine->emPool));
        $app->middleware(new PoolExhaustedToServiceUnavailable(new Psr17Factory()));

        $app->paramResolver(new ConnectionResolver());
        $app->paramResolver(new EntityManagerResolver());
    }

    /**
     * Order matters — specific subclasses first, Throwable catch-all last.
     */
    private static function registerExceptionMappers(WsApplication $app, LoggerInterface $log): void
    {
        $app->onException(
            MessageDeserializationException::class,
            // Do NOT echo the exception message — it can carry mapper internals
            // (paths, expected types). A stable, generic 400 is enough.
            static fn(): Psr7Response => new Psr7Response(
                400,
                ['content-type' => 'application/json'],
                (string) json_encode(['error' => 'invalid request body']),
            ),
        );

        $renderer = new JsonExceptionRenderer($log);
        $app->onException(Throwable::class, $renderer(...));
    }
}
