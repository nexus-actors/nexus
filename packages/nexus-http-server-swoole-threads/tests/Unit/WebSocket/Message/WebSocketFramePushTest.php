<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Threads\Tests\Unit\WebSocket\Message;

use Monadial\Nexus\Http\Server\Swoole\Threads\WebSocket\Message\WebSocketFramePush;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(WebSocketFramePush::class)]
final class WebSocketFramePushTest extends TestCase
{
    #[Test]
    public function default_kind_is_text(): void
    {
        $msg = new WebSocketFramePush(threadId: 2, fd: 17, payload: 'hello');

        self::assertSame(2, $msg->threadId);
        self::assertSame(17, $msg->fd);
        self::assertSame('hello', $msg->payload);
        self::assertSame(WebSocketFramePush::KIND_TEXT, $msg->kind);
        self::assertSame(1000, $msg->closeCode);
        self::assertSame('', $msg->closeReason);
    }

    #[Test]
    public function binary_kind_carries_arbitrary_payload(): void
    {
        $msg = new WebSocketFramePush(threadId: 0, fd: 5, payload: "\x00\xff", kind: WebSocketFramePush::KIND_BINARY);

        self::assertSame(WebSocketFramePush::KIND_BINARY, $msg->kind);
        self::assertSame("\x00\xff", $msg->payload);
    }

    #[Test]
    public function close_kind_carries_code_and_reason(): void
    {
        $msg = new WebSocketFramePush(
            threadId: 1,
            fd: 9,
            payload: '',
            kind: WebSocketFramePush::KIND_CLOSE,
            closeCode: 1011,
            closeReason: 'server error',
        );

        self::assertSame(WebSocketFramePush::KIND_CLOSE, $msg->kind);
        self::assertSame(1011, $msg->closeCode);
        self::assertSame('server error', $msg->closeReason);
    }
}
