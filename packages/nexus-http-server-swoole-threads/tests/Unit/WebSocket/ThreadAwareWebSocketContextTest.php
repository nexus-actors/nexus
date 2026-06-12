<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Threads\Tests\Unit\WebSocket;

use Monadial\Nexus\Http\Server\Swoole\Threads\WebSocket\Message\WebSocketFramePush;
use Monadial\Nexus\Http\Server\Swoole\Threads\WebSocket\ThreadAwareWebSocketContext;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\WebSocket\Server;

use const WEBSOCKET_OPCODE_BINARY;
use const WEBSOCKET_OPCODE_PING;

#[CoversClass(ThreadAwareWebSocketContext::class)]
final class ThreadAwareWebSocketContextTest extends TestCase
{
    #[Test]
    public function id_returns_fd(): void
    {
        $ctx = $this->buildContext(currentThread: 0, ownerThread: 0, fd: 42);

        self::assertSame(42, $ctx->id());
    }

    #[Test]
    public function request_is_passed_through(): void
    {
        $request = new ServerRequest('GET', '/ws');
        $ctx = $this->buildContext(currentThread: 0, ownerThread: 0, fd: 1, request: $request);

        self::assertSame($request, $ctx->request());
    }

    #[Test]
    public function send_on_owner_thread_pushes_directly(): void
    {
        $server = $this->createMock(Server::class);
        $server->expects(self::once())
            ->method('push')
            ->with(7, 'hello');

        $ctx = new ThreadAwareWebSocketContext(
            server: $server,
            currentThreadId: 0,
            ownerThreadId: 0,
            fd: 7,
            request: new ServerRequest('GET', '/'),
            routerSenders: [],
        );

        $ctx->send('hello');
    }

    #[Test]
    public function send_binary_on_owner_thread_pushes_with_binary_opcode(): void
    {
        $server = $this->createMock(Server::class);
        $server->expects(self::once())
            ->method('push')
            ->with(7, "\x01\x02", WEBSOCKET_OPCODE_BINARY);

        $ctx = new ThreadAwareWebSocketContext(
            server: $server,
            currentThreadId: 1,
            ownerThreadId: 1,
            fd: 7,
            request: new ServerRequest('GET', '/'),
            routerSenders: [],
        );

        $ctx->sendBinary("\x01\x02");
    }

    #[Test]
    public function send_ping_on_owner_thread_pushes_with_ping_opcode(): void
    {
        $server = $this->createMock(Server::class);
        $server->expects(self::once())
            ->method('push')
            ->with(7, '', WEBSOCKET_OPCODE_PING);

        $ctx = new ThreadAwareWebSocketContext(
            server: $server,
            currentThreadId: 0,
            ownerThreadId: 0,
            fd: 7,
            request: new ServerRequest('GET', '/'),
            routerSenders: [],
        );

        $ctx->sendPing();
    }

    #[Test]
    public function close_on_owner_thread_disconnects_directly(): void
    {
        $server = $this->createMock(Server::class);
        $server->expects(self::once())
            ->method('disconnect')
            ->with(7, 1011, 'bye');

        $ctx = new ThreadAwareWebSocketContext(
            server: $server,
            currentThreadId: 0,
            ownerThreadId: 0,
            fd: 7,
            request: new ServerRequest('GET', '/'),
            routerSenders: [],
        );

        $ctx->close(1011, 'bye');
    }

    #[Test]
    public function send_on_other_thread_routes_through_router_sender(): void
    {
        $server = $this->createMock(Server::class);
        $server->expects(self::never())->method('push');

        $captured = [];
        $ctx = new ThreadAwareWebSocketContext(
            server: $server,
            currentThreadId: 0,
            ownerThreadId: 2,
            fd: 11,
            request: new ServerRequest('GET', '/'),
            routerSenders: [
                2 => static function (WebSocketFramePush $msg) use (&$captured): void {
                    $captured[] = $msg;
                },
            ],
        );

        $ctx->send('hello');

        self::assertCount(1, $captured);
        $msg = $captured[0];
        self::assertInstanceOf(WebSocketFramePush::class, $msg);
        self::assertSame(2, $msg->threadId);
        self::assertSame(11, $msg->fd);
        self::assertSame('hello', $msg->payload);
        self::assertSame(WebSocketFramePush::KIND_TEXT, $msg->kind);
    }

    #[Test]
    public function send_binary_on_other_thread_routes_with_binary_kind(): void
    {
        $server = $this->createMock(Server::class);
        $server->expects(self::never())->method('push');

        $captured = [];
        $ctx = new ThreadAwareWebSocketContext(
            server: $server,
            currentThreadId: 0,
            ownerThreadId: 2,
            fd: 11,
            request: new ServerRequest('GET', '/'),
            routerSenders: [
                2 => static function (WebSocketFramePush $msg) use (&$captured): void {
                    $captured[] = $msg;
                },
            ],
        );

        $ctx->sendBinary("\x01\x02");

        self::assertCount(1, $captured);
        self::assertSame(WebSocketFramePush::KIND_BINARY, $captured[0]->kind);
        self::assertSame("\x01\x02", $captured[0]->payload);
    }

    #[Test]
    public function close_on_other_thread_routes_with_close_kind(): void
    {
        $server = $this->createMock(Server::class);
        $server->expects(self::never())->method('disconnect');

        $captured = [];
        $ctx = new ThreadAwareWebSocketContext(
            server: $server,
            currentThreadId: 0,
            ownerThreadId: 3,
            fd: 11,
            request: new ServerRequest('GET', '/'),
            routerSenders: [
                3 => static function (WebSocketFramePush $msg) use (&$captured): void {
                    $captured[] = $msg;
                },
            ],
        );

        $ctx->close(1008, 'policy violation');

        self::assertCount(1, $captured);
        $msg = $captured[0];
        self::assertSame(WebSocketFramePush::KIND_CLOSE, $msg->kind);
        self::assertSame(1008, $msg->closeCode);
        self::assertSame('policy violation', $msg->closeReason);
    }

    #[Test]
    public function send_on_other_thread_drops_silently_when_no_sender_registered(): void
    {
        $server = $this->createMock(Server::class);
        $server->expects(self::never())->method('push');
        $server->expects(self::never())->method('disconnect');

        $ctx = new ThreadAwareWebSocketContext(
            server: $server,
            currentThreadId: 0,
            ownerThreadId: 2,
            fd: 11,
            request: new ServerRequest('GET', '/'),
            routerSenders: [],
        );

        // Should not throw.
        $ctx->send('hello');
        $ctx->sendBinary('x');
        $ctx->sendPing();
        $ctx->close(1000, 'bye');

        // Mock expectations above (never push, never disconnect) are the assertions.
        self::assertTrue(true);
    }

    private function buildContext(
        int $currentThread,
        int $ownerThread,
        int $fd,
        ?ServerRequest $request = null,
    ): ThreadAwareWebSocketContext {
        // Stub: never expected to receive calls in id()/request() tests; we
        // configure expects(never()) to silence "no expectations" notice.
        $server = $this->createMock(Server::class);
        $server->expects(self::never())->method('push');
        $server->expects(self::never())->method('disconnect');

        return new ThreadAwareWebSocketContext(
            server: $server,
            currentThreadId: $currentThread,
            ownerThreadId: $ownerThread,
            fd: $fd,
            request: $request ?? new ServerRequest('GET', '/'),
            routerSenders: [],
        );
    }
}
