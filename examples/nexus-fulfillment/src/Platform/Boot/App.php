<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Platform\Boot;

use Closure;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Example\Fulfillment\Platform\Http\ReadinessProbe;
use Monadial\Nexus\Example\Fulfillment\Platform\Http\Routes;
use Monadial\Nexus\Http\Ws\CompiledApplication;
use Monadial\Nexus\Http\Ws\WsApplication;
use Monadial\Nexus\Logger\Formatter\LineFormatter;
use Monadial\Nexus\Logger\Handler\ConsoleHandler;
use Monadial\Nexus\Logger\Level;
use Monadial\Nexus\Logger\NexusLogger;
use Psr\Log\LoggerInterface;
use Throwable;

use function getmypid;

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

                $app = WsApplication::create($system);
                $app->withLogger($log);

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
