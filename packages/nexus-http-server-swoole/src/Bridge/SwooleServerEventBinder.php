<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Bridge;

use Closure;
use Monadial\Nexus\Http\Ws\CompiledWsApplication;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketContext;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketFrame;
use Monadial\Nexus\Runtime\Duration;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Swoole\Coroutine;
use Swoole\Event;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server as HttpServer;
use Swoole\WebSocket\Frame as SwooleFrame;
use Swoole\WebSocket\Server as WebSocketServer;
use Throwable;

use function base64_encode;
use function microtime;
use function preg_match;
use function sha1;

/**
 * @psalm-api
 *
 * Wires Swoole HTTP+WebSocket events to a CompiledApplication. Shared by
 * both worker- and thread-mode runners; the only per-mode variability is
 * the WebSocketContext factory injected into bindWebSocket().
 */
final class SwooleServerEventBinder
{
    /**
     * RFC 6455 §1.3 handshake GUID. Every WebSocket server concatenates the
     * client's Sec-WebSocket-Key with this fixed value, SHA-1 hashes it, and
     * base64-encodes the digest into Sec-WebSocket-Accept to prove it actually
     * speaks the WebSocket protocol. Protocol-defined — never changes.
     */
    private const string RFC6455_HANDSHAKE_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /** RFC 6455 §4.1: Sec-WebSocket-Key is exactly 16 random bytes, base64-encoded. */
    private const string SEC_WEBSOCKET_KEY_PATTERN = '#^[+/0-9A-Za-z]{21}[AQgw]==$#';

    public static function bindRequest(
        HttpServer|WebSocketServer $server,
        ServerRuntime $runtime,
        LoggerInterface $logger,
    ): void {
        $logger->debug('SwooleServerEventBinder: binding Request handler');

        $server->on('Request', static function (Request $req, Response $res) use ($runtime, $logger): void {
            try {
                $app = $runtime->app;

                if ($app === null) {
                    $res->status(503);
                    $res->end('Service not ready');

                    return;
                }

                $psr7 = SwooleRequestTranslator::toPsr7($req);
                SwooleResponseWriter::write($app->handle($psr7), $res);
            } catch (Throwable $e) {
                $logger->error('Request handling failed', ['exception' => $e]);

                if (!$res->isWritable()) {
                    return;
                }

                $res->status(500);
                $res->end('Internal Server Error');
            }
        });
    }

    /**
     * @param Closure(WebSocketServer, int, ServerRequestInterface): WebSocketContext $contextFactory
     */
    public static function bindWebSocket(
        WebSocketServer $server,
        ServerRuntime $runtime,
        Closure $contextFactory,
        LoggerInterface $logger,
    ): void {
        $logger->debug('SwooleServerEventBinder: binding WebSocket handshake/Message/Close');

        // A custom handshake handler replaces Swoole's automatic upgrade AND
        // suppresses the Open event: authorization runs BEFORE the 101 switch
        // (rejections are plain HTTP responses on the not-yet-upgraded
        // connection), and dispatchOpen is invoked here with the authorized
        // request — principal attribute included — once the 101 is flushed.
        $server->on(
            'handshake',
            static function (Request $req, Response $res) use ($server, $runtime, $contextFactory, $logger): bool {
                $logger->debug('Swoole handshake event', ['fd' => $req->fd]);

                try {
                    return self::handshake($server, $req, $res, $runtime, $contextFactory, $logger);
                } catch (Throwable $e) {
                    $logger->error('WebSocket handshake failed', ['exception' => $e]);

                    if ($res->isWritable()) {
                        $res->status(500);
                        $res->end();
                    }

                    return false;
                }
            },
        );

        $server->on(
            'Message',
            static function (WebSocketServer $s, SwooleFrame $frame) use ($runtime, $contextFactory, $logger): void {
                try {
                    $app = $runtime->app;

                    if (!$app instanceof CompiledWsApplication) {
                        return;
                    }

                    $kind = $frame->opcode === 2
                        ? WebSocketFrame::KIND_BINARY
                        : WebSocketFrame::KIND_TEXT;
                    $wsFrame = new WebSocketFrame($kind, $frame->data);
                    $ctx = $contextFactory($s, $frame->fd, new ServerRequest('GET', '/'));
                    $app->dispatcher()->dispatchMessage($ctx, $wsFrame);
                } catch (Throwable $e) {
                    $logger->error('WebSocket Message failed', ['exception' => $e]);
                }
            },
        );

        $server->on(
            'Close',
            static function (WebSocketServer $s, int $fd) use ($runtime, $contextFactory, $logger): void {
                $logger->debug('Swoole Close event', ['fd' => $fd]);

                try {
                    $app = $runtime->app;

                    if (!$app instanceof CompiledWsApplication) {
                        return;
                    }

                    $ctx = $contextFactory($s, $fd, new ServerRequest('GET', '/'));
                    $app->dispatcher()->dispatchClose($ctx, 1000);
                } catch (Throwable $e) {
                    $logger->error('WebSocket Close failed', ['exception' => $e]);
                }
            },
        );
    }

    public static function bindWorkerStop(
        HttpServer|WebSocketServer $server,
        ServerRuntime $runtime,
        Duration $shutdownTimeout,
        LoggerInterface $logger,
    ): void {
        $logger->debug('SwooleServerEventBinder: binding WorkerStop');
        $server->on(
            'WorkerStop',
            static function (HttpServer|WebSocketServer $s, int $workerId) use ($runtime, $shutdownTimeout, $logger): void {
                $logger->info('Worker stopping', ['workerId' => $workerId]);
                $system = $runtime->system;

                if ($system !== null) {
                    // WorkerStop fires outside any coroutine. ActorSystem::shutdown()
                    // calls runtime->yield() inside its deadline loop, which requires
                    // a coroutine context. Wrap in a fresh coroutine when needed.
                    $transport = $runtime->transport;
                    $doShutdown = static function () use ($system, $transport, $shutdownTimeout, $workerId, $logger, $runtime): void {
                        // Stop the transport receive loop FIRST so its coroutine
                        // exits before we yield-wait in ActorSystem::shutdown().
                        // Without this the worker exits with "all coroutines asleep"
                        // because the transport poll loop never gets woken.
                        if ($transport !== null) {
                            $transport->stop();
                        }

                        try {
                            $system->shutdown($shutdownTimeout);
                            $logger->info('Worker ActorSystem shutdown complete', ['workerId' => $workerId]);
                        } catch (Throwable $e) {
                            $logger->error('System shutdown failed in WorkerStop', [
                                'exception' => $e,
                                'workerId' => $workerId,
                            ]);
                        }

                        $runtime->reset();
                    };

                    if (Coroutine::getCid() === -1) {
                        Coroutine::create($doShutdown);
                    } else {
                        $doShutdown();
                    }
                } else {
                    $runtime->reset();
                }
            },
        );
    }

    /**
     * Sliding-window failure counter — 3 failures within 5s triggers
     * Server::shutdown(). Called by each runner from the WorkerStart catch
     * block; the runner provides a mode-specific shutdown message.
     */
    public static function recordFailureAndMaybeShutdown(
        HttpServer|WebSocketServer $server,
        ServerRuntime $runtime,
        LoggerInterface $logger,
        string $shutdownMessage,
    ): void {
        $now = microtime(true);
        $bucket = $runtime->failureBucket;

        if ($bucket['since'] === 0.0 || $now - $bucket['since'] > 5.0) {
            $bucket = ['count' => 1, 'since' => $now];
        } else {
            $bucket['count']++;
        }

        $runtime->failureBucket = $bucket;

        if ($bucket['count'] >= 3) {
            $logger->error($shutdownMessage);
            $server->shutdown();
        }
    }

    /**
     * The pre-upgrade handshake protocol, in order: gate the upgrade request
     * through the application's authorization pipeline, validate the client
     * key, complete the RFC 6455 accept, and dispatch the open event with the
     * authorized request once the 101 is on the wire.
     *
     * @param Closure(WebSocketServer, int, ServerRequestInterface): WebSocketContext $contextFactory
     */
    private static function handshake(
        WebSocketServer $server,
        Request $req,
        Response $res,
        ServerRuntime $runtime,
        Closure $contextFactory,
        LoggerInterface $logger,
    ): bool {
        $app = $runtime->app;

        if (!$app instanceof CompiledWsApplication) {
            return self::deny($res, 404, 'WebSocket not supported');
        }

        $result = $app->handshakeGate()->evaluate(SwooleRequestTranslator::toPsr7($req));
        $authorized = $result->request;

        if ($authorized === null) {
            return self::denyWith($res, $result->rejection);
        }

        $key = self::clientKey($req);

        if ($key === null) {
            return self::deny($res, 400, 'Invalid Sec-WebSocket-Key');
        }

        self::completeUpgrade($res, $key);
        self::dispatchOpenWhenEstablished($server, $req->fd, $authorized, $app, $contextFactory, $logger);

        return true;
    }

    /** Refuse the upgrade with a plain HTTP response on the not-yet-upgraded connection. */
    private static function deny(Response $res, int $status, string $body): bool
    {
        $res->status($status);
        $res->end($body);

        return false;
    }

    /** Refuse the upgrade with the gate's rejection response (401/403/404/...). */
    private static function denyWith(Response $res, ?ResponseInterface $rejection): bool
    {
        if ($rejection === null) {
            return self::deny($res, 403, '');
        }

        SwooleResponseWriter::write($rejection, $res);

        return false;
    }

    /** The client's Sec-WebSocket-Key, or null when absent or malformed. */
    private static function clientKey(Request $req): ?string
    {
        /** @var array<string, string> $headers */
        $headers = $req->header ?? [];
        $key = $headers['sec-websocket-key'] ?? '';

        return preg_match(self::SEC_WEBSOCKET_KEY_PATTERN, $key) === 1
            ? $key
            : null;
    }

    /** Send the RFC 6455 §4.2.2 server handshake: the 101 protocol switch. */
    private static function completeUpgrade(Response $res, string $key): void
    {
        $accept = base64_encode(sha1($key . self::RFC6455_HANDSHAKE_GUID, true));

        $res->header('Upgrade', 'websocket');
        $res->header('Connection', 'Upgrade');
        $res->header('Sec-WebSocket-Accept', $accept);
        $res->header('Sec-WebSocket-Version', '13');
        $res->status(101);
        $res->end();
    }

    /**
     * Dispatch the open event carrying the authorized upgrade request.
     * Server::defer() no longer exists in Swoole 6 — Event::defer runs the
     * callback on the next event-loop tick, after the 101 has been flushed
     * and the connection is established.
     *
     * @param Closure(WebSocketServer, int, ServerRequestInterface): WebSocketContext $contextFactory
     */
    private static function dispatchOpenWhenEstablished(
        WebSocketServer $server,
        int $fd,
        ServerRequestInterface $authorized,
        CompiledWsApplication $app,
        Closure $contextFactory,
        LoggerInterface $logger,
    ): void {
        Event::defer(static function () use ($server, $fd, $authorized, $app, $contextFactory, $logger): void {
            try {
                $ctx = $contextFactory($server, $fd, $authorized);
                $app->dispatcher()->dispatchOpen($ctx, $authorized);
            } catch (Throwable $e) {
                $logger->error('WebSocket Open failed', ['exception' => $e]);
                $server->disconnect($fd, 1011, 'Server error');
            }
        });
    }
}
