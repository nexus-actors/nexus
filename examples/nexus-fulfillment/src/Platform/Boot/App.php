<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Platform\Boot;

use Closure;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Doctrine\Dbal\Http\ConnectionResolver;
use Monadial\Nexus\Doctrine\Dbal\Http\ConnectionScopeMiddleware;
use Monadial\Nexus\Doctrine\Dbal\Http\PoolExhaustedToServiceUnavailable;
use Monadial\Nexus\Doctrine\Orm\Http\EntityManagerResolver;
use Monadial\Nexus\Doctrine\Orm\Http\EntityManagerScopeMiddleware;
use Monadial\Nexus\Example\Fulfillment\Fulfillment\Application\FulfillmentManagerActor;
use Monadial\Nexus\Example\Fulfillment\Fulfillment\Application\ProcessRefFactory;
use Monadial\Nexus\Example\Fulfillment\Inventory\Application\InventoryRefFactory;
use Monadial\Nexus\Example\Fulfillment\Inventory\Infrastructure\ReadModel\InventoryLevelsProjector;
use Monadial\Nexus\Example\Fulfillment\Inventory\Infrastructure\ReadModel\InventoryReadModel;
use Monadial\Nexus\Example\Fulfillment\Orders\Application\OrderRefFactory;
use Monadial\Nexus\Example\Fulfillment\Orders\Infrastructure\ReadModel\OrdersReadModel;
use Monadial\Nexus\Example\Fulfillment\Orders\Infrastructure\ReadModel\OrdersViewProjector;
use Monadial\Nexus\Example\Fulfillment\Platform\Bus\ContextBusActor;
use Monadial\Nexus\Example\Fulfillment\Platform\Bus\Subscribe;
use Monadial\Nexus\Example\Fulfillment\Platform\Http\Auth\DemoTokens;
use Monadial\Nexus\Example\Fulfillment\Platform\Http\InventoryRefFactoryResolver;
use Monadial\Nexus\Example\Fulfillment\Platform\Http\OrderRefFactoryResolver;
use Monadial\Nexus\Example\Fulfillment\Platform\Http\ReadinessProbe;
use Monadial\Nexus\Example\Fulfillment\Platform\Http\Routes;
use Monadial\Nexus\Example\Fulfillment\Platform\Http\VoParamResolver;
use Monadial\Nexus\Http\Auth\Exception\Unauthenticated;
use Monadial\Nexus\Http\Auth\Middleware\AuthenticationMiddleware;
use Monadial\Nexus\Http\Auth\Resolver\FromPrincipalResolver;
use Monadial\Nexus\Http\Exception\HttpException;
use Monadial\Nexus\Http\Ws\CompiledApplication;
use Monadial\Nexus\Http\Ws\WsApplication;
use Monadial\Nexus\Logger\Formatter\LineFormatter;
use Monadial\Nexus\Logger\Handler\ConsoleHandler;
use Monadial\Nexus\Logger\Level;
use Monadial\Nexus\Logger\NexusLogger;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Serialization\Exception\MessageDeserializationException;
use Monadial\Nexus\Serialization\ValinorJsonSerializer;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Log\LoggerInterface;
use Throwable;

use function getmypid;
use function json_encode;

/**
 * Per-worker application factory — the composition root. Every
 * collaborator is constructed once at worker boot and injected.
 */
final class App
{
    /**
     * @return Closure(ActorSystem): CompiledApplication
     */
    public static function factory(FulfillmentConfig $config): Closure
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

                // Passivation window shared by every entity/saga family.
                $passivateAfter = Duration::seconds(300);

                // Spawn context-bus and projectors directly on the system so we
                // can hold the refs for wiring (ActorRegistration does not
                // expose a ref). This mirrors how tictactoe wires GameRefFactory.
                $busRaw = $system->spawn(Props::fromBehavior(ContextBusActor::behavior()), 'context-bus');

                $ordersProjector = $system->spawn(
                    Props::fromBehavior(OrdersViewProjector::behavior(new OrdersReadModel($doctrine->emPool))),
                    OrdersViewProjector::ACTOR_NAME,
                );
                $busRaw->tell(new Subscribe($ordersProjector));

                $inventoryProjector = $system->spawn(
                    Props::fromBehavior(InventoryLevelsProjector::behavior(new InventoryReadModel($doctrine->emPool))),
                    InventoryLevelsProjector::ACTOR_NAME,
                );
                $busRaw->tell(new Subscribe($inventoryProjector));

                // Use the raw ref (ActorRef<object>) for the Subscribe message;
                // cast to ActorRef<Publish> for the *RefFactory contracts.
                /** @var \Monadial\Nexus\Core\Actor\ActorRef<\Monadial\Nexus\Example\Fulfillment\SharedKernel\Bus\Publish> $bus */
                $bus = $busRaw;

                $orders = new OrderRefFactory(
                    $system,
                    $doctrine->eventStore,
                    $doctrine->snapshotStore,
                    $bus,
                    $passivateAfter,
                );

                $inventory = new InventoryRefFactory(
                    $system,
                    $doctrine->eventStore,
                    $doctrine->snapshotStore,
                    $bus,
                    $passivateAfter,
                );

                $processes = new ProcessRefFactory(
                    $system,
                    $doctrine->eventStore,
                    $doctrine->snapshotStore,
                    $orders,
                    $inventory,
                    $passivateAfter,
                );

                // The saga orchestrator: subscribes to OrderPlaced + inventory
                // events and routes them to the per-order process actor.
                $manager = $system->spawn(
                    Props::fromBehavior(FulfillmentManagerActor::behavior($processes)),
                    FulfillmentManagerActor::ACTOR_NAME,
                );
                $busRaw->tell(new Subscribe($manager));

                $app = WsApplication::create($system);
                $app->withLogger($log);

                $app->withMessageSerializer(new ValinorJsonSerializer());
                $app->middleware(new AuthenticationMiddleware(DemoTokens::authenticator(), $log));
                $app->middleware(new ConnectionScopeMiddleware($doctrine->connPool));
                $app->middleware(new EntityManagerScopeMiddleware($doctrine->emPool));
                $app->middleware(new PoolExhaustedToServiceUnavailable(new Psr17Factory()));
                $app->paramResolver(new ConnectionResolver());
                $app->paramResolver(new EntityManagerResolver());
                $app->paramResolver(new FromPrincipalResolver());
                $app->paramResolver(new InventoryRefFactoryResolver($inventory));
                $app->paramResolver(new OrderRefFactoryResolver($orders));
                $app->paramResolver(new VoParamResolver());

                $app->onException(
                    Unauthenticated::class,
                    static fn(): Psr7Response => new Psr7Response(
                        401,
                        ['content-type' => 'application/json', 'www-authenticate' => 'Bearer'],
                        '{"error":"authentication required"}',
                    ),
                );

                $app->onException(
                    MessageDeserializationException::class,
                    static fn(MessageDeserializationException $e): Psr7Response => new Psr7Response(
                        400,
                        ['content-type' => 'application/json'],
                        (string) json_encode(['detail' => $e->getMessage(), 'error' => 'invalid request body']),
                    ),
                );

                $app->onException(
                    HttpException::class,
                    static fn(HttpException $e): Psr7Response => new Psr7Response(
                        $e->status,
                        ['content-type' => 'application/json'],
                        (string) json_encode(['error' => $e->getMessage()]),
                    ),
                );

                $app->onException(
                    Throwable::class,
                    static function (Throwable $e) use ($log): Psr7Response {
                        $log->error('unhandled exception', [
                            'error' => $e::class . ': ' . $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);

                        return new Psr7Response(
                            500,
                            ['content-type' => 'application/json'],
                            '{"error":"Internal Server Error"}',
                        );
                    },
                );

                Routes::register($app, new ReadinessProbe($config->db));

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
        /** @var resource $stderr */
        $stderr = STDERR;

        return NexusLogger::create($system, "worker-{$workerId}")
            ->minLevel(Level::Info)
            ->handler(new ConsoleHandler($stderr, new LineFormatter()))
            ->build();
    }
}
