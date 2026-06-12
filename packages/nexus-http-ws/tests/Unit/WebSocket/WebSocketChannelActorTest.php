<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket\Support\InMemoryWebSocketContext;
use Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket\Support\RecordingChannelActor;
use Monadial\Nexus\Http\Ws\WebSocket\Message\ChannelConnectionClosed;
use Monadial\Nexus\Http\Ws\WebSocket\Message\ChannelConnectionOpened;
use Monadial\Nexus\Http\Ws\WebSocket\Message\ChannelMessageReceived;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketChannelActor;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketFrame;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(WebSocketChannelActor::class)]
final class WebSocketChannelActorTest extends TestCase
{
    #[Test]
    public function opened_message_invokes_on_opened_and_tracks_connection(): void
    {
        $a = new RecordingChannelActor();
        $ctx = new InMemoryWebSocketContext(1);

        $a->handle($this->actorContext(), new ChannelConnectionOpened(1, $ctx, new ServerRequest('GET', '/ws')), 0);

        self::assertSame([['event' => 'opened', 'fd' => 1]], $a->events);
        self::assertSame([$ctx], $a->publicConnections());
    }

    #[Test]
    public function message_routed_to_on_message_and_broadcast_excludes_sender(): void
    {
        $a = new RecordingChannelActor();
        $c1 = new InMemoryWebSocketContext(1);
        $c2 = new InMemoryWebSocketContext(2);
        $a->handle($this->actorContext(), new ChannelConnectionOpened(1, $c1, new ServerRequest('GET', '/ws')), 0);
        $a->handle($this->actorContext(), new ChannelConnectionOpened(2, $c2, new ServerRequest('GET', '/ws')), 0);

        $a->handle(
            $this->actorContext(),
            new ChannelMessageReceived(1, new WebSocketFrame(WebSocketFrame::KIND_TEXT, 'hi')),
            0,
        );

        self::assertSame([], $c1->sentText);
        self::assertSame(['relay:hi'], $c2->sentText);
    }

    #[Test]
    public function message_on_unknown_fd_is_ignored(): void
    {
        $a = new RecordingChannelActor();

        $a->handle(
            $this->actorContext(),
            new ChannelMessageReceived(99, new WebSocketFrame(WebSocketFrame::KIND_TEXT, 'x')),
            0,
        );

        self::assertSame([], $a->events);
    }

    #[Test]
    public function closed_removes_connection_and_invokes_hook(): void
    {
        $a = new RecordingChannelActor();
        $ctx = new InMemoryWebSocketContext(7);
        $a->handle($this->actorContext(), new ChannelConnectionOpened(7, $ctx, new ServerRequest('GET', '/ws')), 0);

        $a->handle($this->actorContext(), new ChannelConnectionClosed(7, 1001), 0);

        self::assertSame([], $a->publicConnections());
        self::assertSame(
            [['event' => 'opened', 'fd' => 7], ['event' => 'closed:1001', 'fd' => 7]],
            $a->events,
        );
    }

    #[Test]
    public function unknown_system_message_is_a_noop(): void
    {
        $a = new RecordingChannelActor();

        $a->handle($this->actorContext(), new stdClass(), 0);

        self::assertSame([], $a->events);
    }

    /** @return ActorContext<object> */
    private function actorContext(): ActorContext
    {
        return $this->createStub(ActorContext::class);
    }
}
