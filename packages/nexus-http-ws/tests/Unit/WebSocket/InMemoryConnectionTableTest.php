<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket;

use Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket\Support\FakeActorRef;
use Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket\Support\InMemoryWebSocketContext;
use Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket\Support\NullHandler;
use Monadial\Nexus\Http\Ws\WebSocket\InMemoryConnectionTable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(InMemoryConnectionTable::class)]
final class InMemoryConnectionTableTest extends TestCase
{
    #[Test]
    public function attach_handler_stores_handler_entry(): void
    {
        $t = new InMemoryConnectionTable();
        $h = new NullHandler();
        $ctx = new InMemoryWebSocketContext(1);
        $t->attachHandler(1, $h, $ctx);

        $e = $t->get(1);
        self::assertNotNull($e);
        self::assertSame($h, $e['handler']);
        self::assertNull($e['channelActor']);
        self::assertNull($e['channelName']);
        self::assertSame($ctx, $e['ctx']);
    }

    #[Test]
    public function attach_channel_stores_channel_entry(): void
    {
        $t = new InMemoryConnectionTable();
        $ref = new FakeActorRef();
        $ctx = new InMemoryWebSocketContext(2);
        $t->attachChannel(2, $ref, 'room/lobby', $ctx);

        $e = $t->get(2);
        self::assertNotNull($e);
        self::assertNull($e['handler']);
        self::assertSame($ref, $e['channelActor']);
        self::assertSame('room/lobby', $e['channelName']);
        self::assertSame($ctx, $e['ctx']);
    }

    #[Test]
    public function get_returns_null_for_unknown_fd(): void
    {
        self::assertNull((new InMemoryConnectionTable())->get(42));
    }

    #[Test]
    public function has_reflects_attach_and_remove(): void
    {
        $t = new InMemoryConnectionTable();
        self::assertFalse($t->has(7));
        $t->attachHandler(7, new NullHandler(), new InMemoryWebSocketContext(7));
        self::assertTrue($t->has(7));
        $t->remove(7);
        self::assertFalse($t->has(7));
    }

    #[Test]
    public function fds_lists_all_attached(): void
    {
        $t = new InMemoryConnectionTable();
        $t->attachHandler(1, new NullHandler(), new InMemoryWebSocketContext(1));
        $t->attachHandler(2, new NullHandler(), new InMemoryWebSocketContext(2));

        $fds = $t->fds();
        sort($fds);
        self::assertSame([1, 2], $fds);
    }
}
