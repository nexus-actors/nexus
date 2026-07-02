<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\WebSocket;

use Monadial\Nexus\Core\Actor\ActorRef;
use Override;

use function array_keys;

/** @psalm-api */
final class InMemoryConnectionTable implements ConnectionTable
{
    /**
     * @var array<int, array{
     *   channelActor: ActorRef|null,
     *   channelName: string|null,
     *   ctx: WebSocketContext,
     *   handler: WebSocketHandler|null
     * }>
     */
    private array $entries = [];

    #[Override]
    public function attachHandler(int $fd, WebSocketHandler $handler, WebSocketContext $ctx): void
    {
        $this->entries[$fd] = [
            'channelActor' => null,
            'channelName' => null,
            'ctx' => $ctx,
            'handler' => $handler,
        ];
    }

    #[Override]
    public function attachChannel(int $fd, ActorRef $actor, string $channelName, WebSocketContext $ctx): void
    {
        $this->entries[$fd] = [
            'channelActor' => $actor,
            'channelName' => $channelName,
            'ctx' => $ctx,
            'handler' => null,
        ];
    }

    #[Override]
    public function get(int $fd): ?array
    {
        return $this->entries[$fd] ?? null;
    }

    #[Override]
    public function remove(int $fd): void
    {
        unset($this->entries[$fd]);
    }

    #[Override]
    public function has(int $fd): bool
    {
        return isset($this->entries[$fd]);
    }

    /** @return list<int> */
    #[Override]
    public function fds(): array
    {
        return array_keys($this->entries);
    }
}
