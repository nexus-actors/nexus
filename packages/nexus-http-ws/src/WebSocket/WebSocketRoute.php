<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\WebSocket;

use Closure;

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
     */
    public function __construct(
        public string $mode,
        public string $path,
        public string $targetClass,
        public ?string $keyFrom,
        public ?Closure $channelFactory = null,
    ) {}

    /** @param class-string $handlerClass */
    public static function handler(string $path, string $handlerClass): self
    {
        return new self(self::MODE_HANDLER, $path, $handlerClass, null);
    }

    /**
     * @param class-string $actorClass
     * @param ?Closure(): WebSocketChannelActor $factory
     */
    public static function channel(string $path, string $actorClass, string $keyFrom, ?Closure $factory = null): self
    {
        return new self(self::MODE_CHANNEL, $path, $actorClass, $keyFrom, $factory);
    }
}
