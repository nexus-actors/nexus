<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\WebSocket;

use Closure;
use Psr\Http\Server\MiddlewareInterface;

/** @psalm-api */
final readonly class WebSocketRoute
{
    public const string MODE_CHANNEL = 'channel';
    public const string MODE_HANDLER = 'handler';

    /**
     * @param class-string $targetClass
     * @param ?Closure(): WebSocketChannelActor $channelFactory Optional custom
     *        factory for channel actors that need constructor-injected
     *        dependencies. When null, the dispatcher calls `new $actorClass()`
     *        (zero-arg construction).
     * @param list<MiddlewareInterface|class-string<MiddlewareInterface>> $middleware
     *        Per-route PSR-15 middleware run by the HandshakeGate against the
     *        upgrade request BEFORE the 101 switch (after global WS middleware).
     */
    public function __construct(
        public string $mode,
        public string $path,
        public string $targetClass,
        public ?string $keyFrom,
        public ?Closure $channelFactory = null,
        public array $middleware = [],
    ) {}

    /**
     * @param class-string $handlerClass
     * @param list<MiddlewareInterface|class-string<MiddlewareInterface>> $middleware
     */
    public static function handler(string $path, string $handlerClass, array $middleware = []): self
    {
        return new self(self::MODE_HANDLER, $path, $handlerClass, null, null, $middleware);
    }

    /**
     * @param class-string $actorClass
     * @param ?Closure(): WebSocketChannelActor $factory
     * @param list<MiddlewareInterface|class-string<MiddlewareInterface>> $middleware
     */
    public static function channel(
        string $path,
        string $actorClass,
        string $keyFrom,
        ?Closure $factory = null,
        array $middleware = [],
    ): self {
        return new self(self::MODE_CHANNEL, $path, $actorClass, $keyFrom, $factory, $middleware);
    }
}
