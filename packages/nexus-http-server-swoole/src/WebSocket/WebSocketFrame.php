<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\WebSocket;

/**
 * @psalm-api
 *
 * Immutable WebSocket frame. The 'kind' field reuses Swoole opcode integers
 * (1=text, 2=binary, 8=close, 9=ping, 10=pong).
 */
final readonly class WebSocketFrame
{
    public const int KIND_TEXT   = 1;
    public const int KIND_BINARY = 2;
    public const int KIND_CLOSE  = 8;
    public const int KIND_PING   = 9;
    public const int KIND_PONG   = 10;

    public function __construct(public int $kind, public string $text)
    {
    }

    public static function text(string $text): self
    {
        return new self(self::KIND_TEXT, $text);
    }

    public static function binary(string $data): self
    {
        return new self(self::KIND_BINARY, $data);
    }

    public function isText(): bool
    {
        return $this->kind === self::KIND_TEXT;
    }

    public function isBinary(): bool
    {
        return $this->kind === self::KIND_BINARY;
    }

    public function isPing(): bool
    {
        return $this->kind === self::KIND_PING;
    }
}
