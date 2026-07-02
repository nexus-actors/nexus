<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket\Support\ArrayContainer;
use Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket\Support\EchoHandler;
use Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket\Support\InMemoryWebSocketContext;
use Monadial\Nexus\Http\Ws\WebSocket\ChannelActorRegistry;
use Monadial\Nexus\Http\Ws\WebSocket\HandlerInstantiator;
use Monadial\Nexus\Http\Ws\WebSocket\InMemoryConnectionTable;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketDispatcher;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketFrame;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketRoute;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketRouter;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(WebSocketDispatcher::class)]
final class WebSocketDispatcherTest extends TestCase
{
    #[Test]
    public function open_with_no_route_match_closes_with_1000(): void
    {
        $d = $this->dispatcher(WebSocketRouter::build([]));
        $ctx = new InMemoryWebSocketContext(1, '/missing');

        $d->dispatchOpen($ctx, new ServerRequest('GET', '/missing'));

        self::assertTrue($ctx->closed);
        self::assertSame(1000, $ctx->closeCode);
    }

    #[Test]
    public function handler_route_resolves_instantiates_and_invokes_open(): void
    {
        $router = WebSocketRouter::build([WebSocketRoute::handler('/ws/echo', EchoHandler::class)]);
        $table = new InMemoryConnectionTable();
        $d = $this->dispatcher($router, $table);
        $ctx = new InMemoryWebSocketContext(2, '/ws/echo');

        $d->dispatchOpen($ctx, new ServerRequest('GET', '/ws/echo'));

        self::assertTrue($table->has(2));
        $entry = $table->get(2);
        self::assertNotNull($entry);
        self::assertInstanceOf(EchoHandler::class, $entry['handler']);
    }

    #[Test]
    public function handler_route_message_dispatch_calls_on_message(): void
    {
        $router = WebSocketRouter::build([WebSocketRoute::handler('/ws/echo', EchoHandler::class)]);
        $d = $this->dispatcher($router);
        $ctx = new InMemoryWebSocketContext(3, '/ws/echo');
        $d->dispatchOpen($ctx, new ServerRequest('GET', '/ws/echo'));

        $d->dispatchMessage($ctx, new WebSocketFrame(WebSocketFrame::KIND_TEXT, 'hi'));

        self::assertSame(['echo:hi'], $ctx->sentText);
    }

    #[Test]
    public function handler_route_close_removes_entry(): void
    {
        $router = WebSocketRouter::build([WebSocketRoute::handler('/ws/echo', EchoHandler::class)]);
        $table = new InMemoryConnectionTable();
        $d = $this->dispatcher($router, $table);
        $ctx = new InMemoryWebSocketContext(4, '/ws/echo');
        $d->dispatchOpen($ctx, new ServerRequest('GET', '/ws/echo'));

        $d->dispatchClose($ctx, 1001);

        self::assertFalse($table->has(4));
    }

    #[Test]
    public function unknown_fd_message_is_silently_dropped(): void
    {
        $d = $this->dispatcher(WebSocketRouter::build([]));
        $ctx = new InMemoryWebSocketContext(99);

        $d->dispatchMessage($ctx, new WebSocketFrame(WebSocketFrame::KIND_TEXT, 'x'));

        self::assertSame([], $ctx->sentText);
    }

    private function dispatcher(WebSocketRouter $router, ?InMemoryConnectionTable $table = null): WebSocketDispatcher
    {
        $system = ActorSystem::create('t', new TestRuntime());

        return new WebSocketDispatcher(
            $router,
            $table ?? new InMemoryConnectionTable(),
            new ChannelActorRegistry($system),
            new HandlerInstantiator(new ArrayContainer()),
        );
    }
}
