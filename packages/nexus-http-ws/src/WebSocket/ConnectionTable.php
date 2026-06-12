<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\WebSocket;

use Monadial\Nexus\Core\Actor\ActorRef;

/**
 * @psalm-api
 *
 * @phpstan-type ConnectionEntry array{
 *   channelActor: ActorRef|null,
 *   channelName: string|null,
 *   ctx: WebSocketContext,
 *   handler: WebSocketHandler|null
 * }
 */
interface ConnectionTable
{
    public function attachHandler(int $fd, WebSocketHandler $handler, WebSocketContext $ctx): void;

    public function attachChannel(int $fd, ActorRef $actor, string $channelName, WebSocketContext $ctx): void;

    /**
     * @return array{channelActor: ActorRef|null, channelName: string|null, ctx: WebSocketContext, handler: WebSocketHandler|null}|null
     */
    public function get(int $fd): ?array;

    public function remove(int $fd): void;

    public function has(int $fd): bool;

    /** @return list<int> */
    public function fds(): array;
}
