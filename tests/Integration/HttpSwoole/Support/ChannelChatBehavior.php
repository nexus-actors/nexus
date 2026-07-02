<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\HttpSwoole\Support;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketChannelActor;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketContext;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketFrame;
use Override;

/**
 * @psalm-api
 *
 * Test-only chat channel actor. On incoming text frames, broadcasts to every
 * connection in the channel (sender included).
 *
 * @extends WebSocketChannelActor<null>
 */
final class ChannelChatBehavior extends WebSocketChannelActor
{
    #[Override]
    public function initialState(): mixed
    {
        return null;
    }

    /**
     * @psalm-suppress MixedReturnTypeCoercion — BehaviorWithState::same() is generic over mixed; state type is null.
     */
    #[Override]
    public function onMessage(
        ActorContext $ctx,
        WebSocketContext $conn,
        WebSocketFrame $frame,
        mixed $state,
    ): BehaviorWithState {
        $this->broadcast($frame->text);

        return BehaviorWithState::same();
    }
}
