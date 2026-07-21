<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\WebSocket;

use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Http\Ws\WebSocket\Exception\ChannelCapacityExceededException;
use Monadial\Nexus\Http\Ws\WebSocket\Message\ChannelConnectionClosed;
use Monadial\Nexus\Http\Ws\WebSocket\Message\ChannelConnectionOpened;
use Monadial\Nexus\Http\Ws\WebSocket\Message\ChannelMessageReceived;
use Monadial\Nexus\Runtime\Mailbox\MailboxConfig;
use Monadial\Nexus\Runtime\Mailbox\OverflowStrategy;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use RuntimeException;
use Throwable;

/**
 * @psalm-api
 *
 * Runtime-agnostic dispatcher. Runners call dispatchOpen/Message/Close
 * for every WebSocket lifecycle event. The dispatcher routes via the
 * WebSocketRouter, instantiates handlers via the HandlerInstantiator,
 * resolves channel actors via the registry, and maintains the
 * ConnectionTable.
 */
final readonly class WebSocketDispatcher
{
    /** Bounded mailbox capacity for channel actors, bounding per-channel queue memory. */
    private const int CHANNEL_MAILBOX_CAPACITY = 1_024;

    private LoggerInterface $logger;

    public function __construct(
        private WebSocketRouter $router,
        private ConnectionTable $table,
        private ChannelActorRegistry $registry,
        private HandlerInstantiator $instantiator,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function dispatchOpen(WebSocketContext $ctx, ServerRequestInterface $upgrade): void
    {
        $path = $upgrade->getUri()->getPath();
        $match = $this->router->match($path);

        if ($match === null) {
            $this->logger->debug(
                'WebSocket open: no route match — closing 1000',
                ['fd' => $ctx->id(), 'path' => $path],
            );
            $ctx->close(1000, 'No WebSocket route');

            return;
        }

        $route = $match['route'];

        try {
            if ($route->mode === WebSocketRoute::MODE_HANDLER) {
                /** @var class-string<WebSocketHandler> $handlerClass */
                $handlerClass = $route->targetClass;
                $this->logger->debug('WebSocket open: handler route matched', [
                    'class' => $handlerClass,
                    'fd' => $ctx->id(),
                    'path' => $path,
                ]);

                // Attach path params (same rationale as the channel branch).
                $enriched = $upgrade;

                foreach ($match['params'] as $paramName => $paramValue) {
                    $enriched = $enriched->withAttribute($paramName, $paramValue);
                }

                $ctx = $ctx->withRequest($enriched);
                $handler = $this->instantiator->instantiate($handlerClass, $ctx);
                $handler->onOpen();
                $this->table->attachHandler($ctx->id(), $handler, $ctx);

                return;
            }

            if ($route->mode === WebSocketRoute::MODE_CHANNEL) {
                /** @var class-string<WebSocketChannelActor> $actorClass */
                $actorClass = $route->targetClass;
                $keyFrom = $route->keyFrom ?? '';
                $key = $match['params'][$keyFrom] ?? '';
                $name = ChannelActorNameResolver::resolve($key);
                $this->logger->debug('WebSocket open: channel route matched', [
                    'actorClass' => $actorClass,
                    'actorName' => $name,
                    'fd' => $ctx->id(),
                    'key' => $key,
                    'path' => $path,
                ]);

                // Attach FastRoute path params to the stored request so the
                // channel actor can read `$conn->request()->getAttribute('id')`
                // for a route like `/ws/games/{id}`. Without this, params
                // matched here vanish before the actor sees them.
                $enriched = $upgrade;

                foreach ($match['params'] as $paramName => $paramValue) {
                    $enriched = $enriched->withAttribute($paramName, $paramValue);
                }

                $ctx = $ctx->withRequest($enriched);

                $factory = $route->channelFactory;

                try {
                    $ref = $this->registry->resolveOrSpawn(
                        $name,
                        // Channel actors default to a bounded mailbox so a
                        // flooding connection cannot grow one channel's queue
                        // without limit (SEC-002).
                        Props::fromStatefulFactory(
                            // Reflection-based construction keeps the zero-arg
                            // fallback type-safe for the dynamic class-string;
                            // it only runs once per channel-actor spawn.
                            $factory ?? static fn(): WebSocketChannelActor => new ReflectionClass(
                                $actorClass,
                            )->newInstance(),
                        )->withMailbox(
                            MailboxConfig::bounded(self::CHANNEL_MAILBOX_CAPACITY, OverflowStrategy::DropNewest),
                        ),
                    );
                } catch (ChannelCapacityExceededException $e) {
                    $this->logger->warning('WebSocket channel cap reached; refusing connection', [
                        'fd' => $ctx->id(),
                        'path' => $path,
                    ]);
                    // 1013 Try Again Later: the cap is a transient resource limit.
                    $ctx->close(1013, 'Channel capacity reached');

                    return;
                }

                $ref->tell(new ChannelConnectionOpened($ctx->id(), $ctx, $enriched));
                $this->table->attachChannel($ctx->id(), $ref, $name, $ctx);

                return;
            }

            throw new RuntimeException("Unknown WebSocket route mode: {$route->mode}");
        } catch (Throwable $e) {
            $this->logger->error('WebSocket open dispatch failed', ['exception' => $e, 'fd' => $ctx->id()]);
            $ctx->close(1011, 'Server error');
        }
    }

    public function dispatchMessage(WebSocketContext $ctx, WebSocketFrame $frame): void
    {
        $entry = $this->table->get($ctx->id());

        if ($entry === null) {
            return;
        }

        try {
            if ($entry['handler'] !== null) {
                $entry['handler']->onMessage($frame);

                return;
            }

            if ($entry['channelActor'] !== null) {
                $entry['channelActor']->tell(new ChannelMessageReceived($ctx->id(), $frame));
            }
        } catch (Throwable $e) {
            $this->logger->error('WebSocket message dispatch failed', ['exception' => $e, 'fd' => $ctx->id()]);
        }
    }

    public function dispatchClose(WebSocketContext $ctx, int $code): void
    {
        $entry = $this->table->get($ctx->id());

        if ($entry === null) {
            return;
        }

        $this->logger->debug('WebSocket close: dispatching', [
            'channelName' => $entry['channelName'],
            'closeCode' => $code,
            'fd' => $ctx->id(),
            'mode' => $entry['handler'] !== null ? 'handler' : 'channel',
        ]);

        try {
            if ($entry['handler'] !== null) {
                $entry['handler']->onClose($code);
            } elseif ($entry['channelActor'] !== null) {
                $entry['channelActor']->tell(new ChannelConnectionClosed($ctx->id(), $code));
            }
        } catch (Throwable $e) {
            $this->logger->error('WebSocket close dispatch failed', ['exception' => $e, 'fd' => $ctx->id()]);
        } finally {
            $this->table->remove($ctx->id());
        }
    }
}
