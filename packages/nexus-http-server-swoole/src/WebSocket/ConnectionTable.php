<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\WebSocket;

use Monadial\Nexus\Core\Actor\ActorRef;

use function array_keys;

/**
 * @psalm-api
 *
 * Per-worker/per-thread map: fd -> ConnectionEntry. Looked up on every
 * Message and Close event.
 *
 * For handler-mode connections, the entry's $handler is set.
 * For channel-mode connections, $channelName and $channelActor are set so
 * the dispatcher knows which channel actor to notify.
 */
final class ConnectionTable
{
    /** @var array<int, array{handler:?WebSocketHandler, channelName:?string, channelActor:?ActorRef<object>, ctx:WebSocketContext}> */
    private array $entries = [];

    public function attachHandler(int $fd, WebSocketHandler $handler, WebSocketContext $ctx): void
    {
        $this->entries[$fd] = [
            'channelActor' => null,
            'channelName'  => null,
            'ctx'          => $ctx,
            'handler'      => $handler,
        ];
    }

    /** @param ActorRef<object> $actor */
    public function attachChannel(int $fd, ActorRef $actor, string $channelName, WebSocketContext $ctx): void
    {
        $this->entries[$fd] = [
            'channelActor' => $actor,
            'channelName'  => $channelName,
            'ctx'          => $ctx,
            'handler'      => null,
        ];
    }

    /** @return array{handler:?WebSocketHandler, channelName:?string, channelActor:?ActorRef<object>, ctx:WebSocketContext}|null */
    public function get(int $fd): ?array
    {
        return $this->entries[$fd] ?? null;
    }

    public function remove(int $fd): void
    {
        unset($this->entries[$fd]);
    }

    public function has(int $fd): bool
    {
        return isset($this->entries[$fd]);
    }

    /** @return list<int> */
    public function fds(): array
    {
        return array_keys($this->entries);
    }
}
