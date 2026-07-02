<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\WebSocket;

/** @psalm-api */
final readonly class WebSocketFrame
{
    public const int KIND_BINARY = 2;
    public const int KIND_TEXT = 1;

    public function __construct(public int $kind, public string $text) {}
}
