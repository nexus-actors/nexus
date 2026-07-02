<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket\Support;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketChannelActor;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketContext;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketFrame;

/** @extends WebSocketChannelActor<int> */
final class RecordingChannelActor extends WebSocketChannelActor
{
    /** @var list<array{event: string, fd: int}> */
    public array $events = [];

    public function initialState(): mixed
    {
        return 0;
    }

    public function onOpened(ActorContext $ctx, WebSocketContext $conn, mixed $state): BehaviorWithState
    {
        $this->events[] = ['event' => 'opened', 'fd' => $conn->id()];

        return BehaviorWithState::same();
    }

    public function onMessage(
        ActorContext $ctx,
        WebSocketContext $conn,
        WebSocketFrame $frame,
        mixed $state,
    ): BehaviorWithState {
        $this->events[] = ['event' => 'message:' . $frame->text, 'fd' => $conn->id()];
        $this->broadcast('relay:' . $frame->text, exceptFd: $conn->id());

        return BehaviorWithState::same();
    }

    public function onClosed(ActorContext $ctx, WebSocketContext $conn, int $code, mixed $state): BehaviorWithState
    {
        $this->events[] = ['event' => 'closed:' . $code, 'fd' => $conn->id()];

        return BehaviorWithState::same();
    }

    /** @return list<WebSocketContext> */
    public function publicConnections(): array
    {
        return $this->connections();
    }
}
