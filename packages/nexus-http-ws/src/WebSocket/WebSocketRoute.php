<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\WebSocket;

/** @psalm-api */
final readonly class WebSocketRoute
{
    public const string MODE_CHANNEL = 'channel';
    public const string MODE_HANDLER = 'handler';

    /** @param class-string $targetClass */
    public function __construct(
        public string $mode,
        public string $path,
        public string $targetClass,
        public ?string $keyFrom,
    ) {}

    /** @param class-string $handlerClass */
    public static function handler(string $path, string $handlerClass): self
    {
        return new self(self::MODE_HANDLER, $path, $handlerClass, null);
    }

    /** @param class-string $actorClass */
    public static function channel(string $path, string $actorClass, string $keyFrom): self
    {
        return new self(self::MODE_CHANNEL, $path, $actorClass, $keyFrom);
    }
}
