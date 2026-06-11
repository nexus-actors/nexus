<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Tests\Unit\WebSocket;

use Monadial\Nexus\Http\Server\Swoole\WebSocket\ConnectionTable;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketContext;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketFrame;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketHandler;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

final class _StubCtx implements WebSocketContext
{
    public function __construct(public readonly int $id)
    {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function request(): ServerRequestInterface
    {
        return new ServerRequest('GET', '/');
    }

    public function send(string $text): void
    {
        // no-op stub
    }

    public function sendBinary(string $data): void
    {
        // no-op stub
    }

    public function sendPing(): void
    {
        // no-op stub
    }

    public function close(int $code = 1000, string $reason = ''): void
    {
        // no-op stub
    }
}

final class _StubHandler implements WebSocketHandler
{
    public function onMessage(WebSocketFrame $frame): void
    {
        // no-op stub
    }

    public function onClose(int $closeCode): void
    {
        // no-op stub
    }
}

#[CoversClass(ConnectionTable::class)]
final class ConnectionTableTest extends TestCase
{
    #[Test]
    public function attachHandlerRecordsEntry(): void
    {
        $table = new ConnectionTable();
        $ctx = new _StubCtx(42);
        $h = new _StubHandler();

        $table->attachHandler(42, $h, $ctx);

        $entry = $table->get(42);
        self::assertNotNull($entry);
        self::assertSame($h, $entry['handler']);
        self::assertNull($entry['channelName']);
    }

    #[Test]
    public function attachChannelRecordsEntry(): void
    {
        $table = new ConnectionTable();
        $ctx = new _StubCtx(7);

        $table->attachChannel(7, 'ws-channel-abc', $ctx);

        $entry = $table->get(7);
        self::assertNotNull($entry);
        self::assertSame('ws-channel-abc', $entry['channelName']);
        self::assertNull($entry['handler']);
    }

    #[Test]
    public function removeDropsEntry(): void
    {
        $table = new ConnectionTable();
        $ctx = new _StubCtx(99);
        $table->attachHandler(99, new _StubHandler(), $ctx);
        self::assertTrue($table->has(99));

        $table->remove(99);

        self::assertFalse($table->has(99));
        self::assertNull($table->get(99));
    }

    #[Test]
    public function fdsListsAll(): void
    {
        $table = new ConnectionTable();
        $table->attachHandler(1, new _StubHandler(), new _StubCtx(1));
        $table->attachHandler(2, new _StubHandler(), new _StubCtx(2));

        self::assertSame([1, 2], $table->fds());
    }
}
