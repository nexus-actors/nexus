<?php

declare(strict_types=1);

/**
 * Thread-mode HTTP + WebSocket server example.
 *
 * Boots a Swoole SWOOLE_THREAD server on 0.0.0.0:8080 with 2 worker
 * threads. Each thread gets its own ActorSystem + WorkerNode + NexusLogger
 * wired into BOTH the runner config (so SwooleThreadServer / EventBinder
 * lifecycle logs fire) AND the WsApplication (so WebSocketDispatcher,
 * ChannelActorRegistry, HandlerInstantiator, and WebSocketChannelActor
 * debug logs fire too).
 *
 * Run:
 *   docker compose exec php-swoole php examples/thread-server.php
 *
 * Then:
 *   curl http://127.0.0.1:8080/
 *   curl http://127.0.0.1:8080/health
 *   curl http://127.0.0.1:8080/hello/world
 *
 * Ctrl+C to stop.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Response\JsonResponse;
use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Http\Server\Swoole\Threads\Server\SwooleThreadConfig;
use Monadial\Nexus\Http\Server\Swoole\Threads\Server\SwooleThreadServer;
use Monadial\Nexus\Http\Ws\CompiledApplication;
use Monadial\Nexus\Http\Ws\WebSocket\Attribute\FromContext;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketContext;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketFrame;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketHandler;
use Monadial\Nexus\Http\Ws\WsApplication;
use Monadial\Nexus\Logger\Formatter\LineFormatter;
use Monadial\Nexus\Logger\Handler\ConsoleHandler;
use Monadial\Nexus\Logger\Level;
use Monadial\Nexus\Logger\Mdc;
use Monadial\Nexus\Logger\NexusLogger;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\WorkerPool\WorkerNode;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

/**
 * @psalm-api — instantiated by HandlerInstantiator via the `$app->ws()` class-string route.
 */
final class EchoHandler extends WebSocketHandler
{
    public function __construct(
        #[FromContext] private readonly WebSocketContext $ctx,
        private readonly LoggerInterface $log,
    ) {
    }

    #[Override]
    public function onOpen(): void
    {
        $this->log->info('EchoHandler open', ['fd' => $this->ctx->id()]);
    }

    #[Override]
    public function onMessage(WebSocketFrame $frame): void
    {
        $this->ctx->send('echo:' . $frame->text);
    }

    #[Override]
    public function onClose(int $code): void
    {
        $this->log->info('EchoHandler close', ['code' => $code, 'fd' => $this->ctx->id()]);
    }
}

final class LoggerContainer implements ContainerInterface
{
    public function __construct(private readonly LoggerInterface $logger) {}

    #[Override]
    public function get(string $id): mixed
    {
        if ($id === LoggerInterface::class) {
            return $this->logger;
        }

        throw new class ('not found: ' . $id) extends RuntimeException implements NotFoundExceptionInterface {};
    }

    #[Override]
    public function has(string $id): bool
    {
        return $id === LoggerInterface::class;
    }
}

// Bootstrap a tiny synchronous logger that the runner uses BEFORE the
// per-thread ActorSystem (and hence per-thread NexusLogger) exists.
$bootstrapLogger = new class implements LoggerInterface {

    use LoggerTrait;

    /**
     * @param array<array-key, mixed> $context
     */
    #[Override]
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $ctx = $context === []
            ? ''
            : ' ' . (string) json_encode($context);
        $line = sprintf(
            '[%s] BOOT.%s: %s%s',
            (new DateTimeImmutable())->format('Y-m-d\\TH:i:s.v\\Z'),
            strtoupper((string) $level),
            (string) $message,
            $ctx,
        );
        fwrite(STDERR, $line . "\n");
    }
};

SwooleThreadServer::run(
    SwooleThreadConfig::bind('0.0.0.0', 8080)
        ->threads(8)
        ->enableWebSocket(true)
        ->shutdownTimeout(Duration::seconds(5))
        ->logger($bootstrapLogger),
    static function (ActorSystem $system, WorkerNode $node): CompiledApplication {
        // Thread-level MDC — set once at startup, auto-attached to every record.
        // putStatic() bypasses coroutine context so values survive into request coroutines.
        $host = gethostname();
        $pid = getmypid();
        Mdc::putStatic('host', $host === false ? 'unknown' : $host);
        Mdc::putStatic('pid', $pid === false ? 0 : $pid);
        Mdc::putStatic('threadId', $node->workerId());
        Mdc::putStatic('service', 'thread-server');

        $logger = NexusLogger::create($system, "thread-{$node->workerId()}")
            ->minLevel(Level::Debug)
            ->handler(new ConsoleHandler(STDOUT, new LineFormatter()))
            ->build();

        $logger->info('thread up');

        $app = WsApplication::create($system)
            ->withLogger($logger)
            ->withContainer(new LoggerContainer($logger));

        $app->get('/', static function () use ($logger, $node): mixed {
            // Per-request coroutine-local MDC (ext-swoole auto-detected by Mdc).
            Mdc::put('requestId', bin2hex(random_bytes(4)));
            Mdc::put('route', 'GET /');
            $logger->debug('handling root');

            return JsonResponse::ok([
                'links' => [
                    ['href' => '/', 'rel' => 'self'],
                    ['href' => '/health', 'rel' => 'health'],
                    ['href' => '/hello/{name}', 'rel' => 'greeting'],
                    ['href' => '/ws/echo', 'rel' => 'echo-ws'],
                ],
                'name' => 'nexus-http-server-swoole-threads example',
                'pid' => getmypid(),
                'tid' => $node->workerId(),
            ]);
        });

        $app->get('/health', static fn(): mixed => Response::ok());

        $app->get('/hello/{name}', static function (ServerRequestInterface $req) use ($logger, $node): mixed {
            $name = (string) $req->getAttribute('name');
            Mdc::put('requestId', bin2hex(random_bytes(4)));
            Mdc::put('route', 'GET /hello/{name}');
            $logger->info('greeting {name}', ['name' => $name]);

            return JsonResponse::ok(['greeting' => 'Hello, ' . $name . '!', 'tid' => $node->workerId()]);
        });

        $app->ws('/ws/echo', EchoHandler::class);

        return $app->compile();
    },
);
