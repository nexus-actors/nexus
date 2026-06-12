<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Threads\WebSocket\Message;

/**
 * @psalm-api
 *
 * System message: "push this payload to the given fd on the owning thread".
 * Sent via WorkerTransport when a channel actor on thread X needs to write
 * to an fd whose Swoole\WebSocket\Server connection lives on thread Y.
 *
 * Handled by each thread's WebSocket router actor — looks up the fd in
 * the local ConnectionTable, pushes via the local Swoole server.
 */
final readonly class WebSocketFramePush
{
    public const int KIND_TEXT   = 1;
    public const int KIND_BINARY = 2;
    public const int KIND_PING   = 9;
    public const int KIND_CLOSE  = 8;

    public function __construct(
        public int $threadId,
        public int $fd,
        public string $payload,
        public int $kind = self::KIND_TEXT,
        public int $closeCode = 1000,
        public string $closeReason = '',
    ) {}
}
