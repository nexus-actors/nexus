<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\WebSocket;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use Monadial\Nexus\Core\Actor\StatefulActorHandler;
use Monadial\Nexus\Http\Ws\WebSocket\Message\ChannelConnectionClosed;
use Monadial\Nexus\Http\Ws\WebSocket\Message\ChannelConnectionOpened;
use Monadial\Nexus\Http\Ws\WebSocket\Message\ChannelMessageReceived;
use Override;

use function array_values;
use function count;

/**
 * @psalm-api
 *
 * Per-key channel actor base. Translates internal Channel*** messages into
 * typed onOpened/onMessage/onClosed hooks. Maintains a connection set the
 * subclass can broadcast over via the protected helpers.
 *
 * @template S
 * @implements StatefulActorHandler<object, S>
 */
abstract class WebSocketChannelActor implements StatefulActorHandler
{
    /** @var array<int, WebSocketContext> */
    private array $attached = [];

    /**
     * @param ActorContext<object> $ctx
     * @param S $state
     * @return BehaviorWithState<object, S>
     */
    #[Override]
    final public function handle(ActorContext $ctx, object $message, mixed $state): BehaviorWithState
    {
        if ($message instanceof ChannelConnectionOpened) {
            $ctx->log()->debug('WebSocketChannelActor: connection opened', [
                'attached' => count($this->attached) + 1,
                'fd' => $message->fd,
            ]);
            $this->attached[$message->fd] = $message->ctx;

            return $this->onOpened($ctx, $message->ctx, $state);
        }

        if ($message instanceof ChannelMessageReceived) {
            $conn = $this->attached[$message->fd] ?? null;

            if ($conn === null) {
                $ctx->log()->debug('WebSocketChannelActor: message on unknown fd dropped', ['fd' => $message->fd]);

                return BehaviorWithState::same();
            }

            return $this->onMessage($ctx, $conn, $message->frame, $state);
        }

        if ($message instanceof ChannelConnectionClosed) {
            $conn = $this->attached[$message->fd] ?? null;

            if ($conn === null) {
                return BehaviorWithState::same();
            }

            unset($this->attached[$message->fd]);
            $ctx->log()->debug('WebSocketChannelActor: connection closed', [
                'attached' => count($this->attached),
                'closeCode' => $message->code,
                'fd' => $message->fd,
            ]);

            return $this->onClosed($ctx, $conn, $message->code, $state);
        }

        return $this->handleAppMessage($ctx, $message, $state);
    }

    /** @return S */
    #[Override]
    abstract public function initialState(): mixed;

    /**
     * @param ActorContext<object> $ctx
     * @param S $state
     * @return BehaviorWithState<object, S>
     */
    abstract public function onMessage(
        ActorContext $ctx,
        WebSocketContext $conn,
        WebSocketFrame $frame,
        mixed $state,
    ): BehaviorWithState;

    /** @return list<WebSocketContext> */
    final protected function connections(): array
    {
        return array_values($this->attached);
    }

    final protected function broadcast(string $text, ?int $exceptFd = null): void
    {
        foreach ($this->attached as $fd => $conn) {
            if ($fd === $exceptFd) {
                continue;
            }

            $conn->send($text);
        }
    }

    /**
     * Hook for messages that are not lifecycle events — typically replies
     * from actors this channel commands. Default is a no-op; override to
     * cache state or broadcast to attached connections.
     *
     * @param ActorContext<object> $ctx
     * @param S $state
     * @return BehaviorWithState<object, S>
     */
    public function handleAppMessage(ActorContext $ctx, object $message, mixed $state): BehaviorWithState
    {
        return BehaviorWithState::same();
    }

    /**
     * @param ActorContext<object> $ctx
     * @param S $state
     * @return BehaviorWithState<object, S>
     */
    public function onOpened(ActorContext $ctx, WebSocketContext $conn, mixed $state): BehaviorWithState
    {
        return BehaviorWithState::same();
    }

    /**
     * @param ActorContext<object> $ctx
     * @param S $state
     * @return BehaviorWithState<object, S>
     */
    public function onClosed(ActorContext $ctx, WebSocketContext $conn, int $code, mixed $state): BehaviorWithState
    {
        return BehaviorWithState::same();
    }
}
