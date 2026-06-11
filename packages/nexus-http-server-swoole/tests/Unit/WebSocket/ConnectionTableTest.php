<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Tests\Unit\WebSocket;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\ConnectionTable;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketContext;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketFrame;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketHandler;
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Duration;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use stdClass;

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

/**
 * @template-implements ActorRef<object>
 */
final class _StubActorRef implements ActorRef
{
    private readonly ActorPath $path;

    public function __construct(string $name)
    {
        $this->path = ActorPath::root()->child('user')->child($name);
    }

    public function tell(object $message): void
    {
        // no-op stub
    }

    public function ask(object $message, Duration $timeout): Future
    {
        return Future::resolved(new stdClass());
    }

    public function path(): ActorPath
    {
        return $this->path;
    }

    public function isAlive(): bool
    {
        return true;
    }
}

#[CoversClass(ConnectionTable::class)]
final class ConnectionTableTest extends TestCase
{
    #[Test]
    public function attach_handler_records_entry(): void
    {
        $table = new ConnectionTable();
        $ctx = new _StubCtx(42);
        $h = new _StubHandler();

        $table->attachHandler(42, $h, $ctx);

        $entry = $table->get(42);
        self::assertNotNull($entry);
        self::assertSame($h, $entry['handler']);
        self::assertNull($entry['channelName']);
        self::assertNull($entry['channelActor']);
    }

    #[Test]
    public function attach_channel_records_entry(): void
    {
        $table = new ConnectionTable();
        $ctx = new _StubCtx(7);
        $actor = new _StubActorRef('ws-channel-abc');

        $table->attachChannel(7, $actor, 'ws-channel-abc', $ctx);

        $entry = $table->get(7);
        self::assertNotNull($entry);
        self::assertSame('ws-channel-abc', $entry['channelName']);
        self::assertSame($actor, $entry['channelActor']);
        self::assertNull($entry['handler']);
    }

    #[Test]
    public function remove_drops_entry(): void
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
    public function fds_lists_all(): void
    {
        $table = new ConnectionTable();
        $table->attachHandler(1, new _StubHandler(), new _StubCtx(1));
        $table->attachHandler(2, new _StubHandler(), new _StubCtx(2));

        self::assertSame([1, 2], $table->fds());
    }
}
