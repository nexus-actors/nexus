<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\HttpSwoole\Support;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\Message\ChannelConnectionClosed;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\Message\ChannelConnectionOpened;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\Message\ChannelMessageReceived;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketContext;

/**
 * @psalm-api
 *
 * Test-only chat channel behavior. Tracks open WebSocket connections in a
 * fd-keyed map; on incoming text frames, broadcasts to every other connection
 * (sender excluded). When the last connection closes, the actor stops.
 */
final class ChannelChatBehavior
{
    /**
     * @return Props<object>
     */
    public static function props(): Props
    {
        /** @var Behavior<object> $behavior */
        $behavior = Behavior::withState(
            ['ctx' => []],
            static function (ActorContext $ctx, object $msg, array $state): BehaviorWithState {
                if ($msg instanceof ChannelConnectionOpened) {
                    /** @var array<int, WebSocketContext> $newCtx */
                    $newCtx           = $state['ctx'];
                    $newCtx[$msg->fd] = $msg->ctx;

                    return BehaviorWithState::next(['ctx' => $newCtx]);
                }

                if ($msg instanceof ChannelMessageReceived) {
                    /** @var array<int, WebSocketContext> $contexts */
                    $contexts = $state['ctx'];

                    foreach ($contexts as $fd => $c) {
                        if ($fd !== $msg->fd) {
                            $c->send($msg->frame->text);
                        }
                    }

                    return BehaviorWithState::same();
                }

                if ($msg instanceof ChannelConnectionClosed) {
                    /** @var array<int, WebSocketContext> $newCtx */
                    $newCtx = $state['ctx'];
                    unset($newCtx[$msg->fd]);

                    return $newCtx === []
                        ? BehaviorWithState::stopped()
                        : BehaviorWithState::next(['ctx' => $newCtx]);
                }

                return BehaviorWithState::same();
            },
        );

        return Props::fromBehavior($behavior);
    }
}
