<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\WebSocket;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Http\Ws\WebSocket\Message\ChannelConnectionClosed;
use Monadial\Nexus\Http\Ws\WebSocket\Message\ChannelConnectionOpened;
use Monadial\Nexus\Http\Ws\WebSocket\Message\ChannelMessageReceived;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
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
final class WebSocketDispatcher
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly WebSocketRouter $router,
        private readonly ConnectionTable $table,
        private readonly ChannelActorRegistry $registry,
        private readonly HandlerInstantiator $instantiator,
        private readonly ActorSystem $system,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function dispatchOpen(WebSocketContext $ctx, ServerRequestInterface $upgrade): void
    {
        $match = $this->router->match($upgrade->getUri()->getPath());

        if ($match === null) {
            $ctx->close(1000, 'No WebSocket route');

            return;
        }

        $route = $match['route'];

        try {
            if ($route->mode === WebSocketRoute::MODE_HANDLER) {
                /** @var class-string<WebSocketHandler> $handlerClass */
                $handlerClass = $route->targetClass;
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

                /** @psalm-suppress InvalidArgument, UnsafeInstantiation */
                $ref = $this->registry->resolveOrSpawn(
                    $name,
                    Props::fromStatefulFactory(static fn() => new $actorClass()),
                );
                $ref->tell(new ChannelConnectionOpened($ctx->id(), $ctx, $upgrade));
                $this->table->attachChannel($ctx->id(), $ref, $name, $ctx);

                return;
            }

            throw new RuntimeException("Unknown WebSocket route mode: {$route->mode}");
        } catch (Throwable $e) {
            $this->logger->error('WebSocket open dispatch failed', ['exception' => $e]);
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
            $this->logger->error('WebSocket message dispatch failed', ['exception' => $e]);
        }
    }

    public function dispatchClose(WebSocketContext $ctx, int $code): void
    {
        $entry = $this->table->get($ctx->id());

        if ($entry === null) {
            return;
        }

        try {
            if ($entry['handler'] !== null) {
                $entry['handler']->onClose($code);
            } elseif ($entry['channelActor'] !== null) {
                $entry['channelActor']->tell(new ChannelConnectionClosed($ctx->id(), $code));
            }
        } catch (Throwable $e) {
            $this->logger->error('WebSocket close dispatch failed', ['exception' => $e]);
        } finally {
            $this->table->remove($ctx->id());
        }
    }
}
