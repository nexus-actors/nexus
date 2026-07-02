<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket;

use Monadial\Nexus\Http\Ws\WebSocket\WebSocketFrame;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(WebSocketFrame::class)]
final class WebSocketFrameTest extends TestCase
{
    #[Test]
    public function text_frame_carries_text_payload(): void
    {
        $f = new WebSocketFrame(WebSocketFrame::KIND_TEXT, 'hi');
        self::assertSame(WebSocketFrame::KIND_TEXT, $f->kind);
        self::assertSame('hi', $f->text);
    }

    #[Test]
    public function binary_frame_carries_binary_payload(): void
    {
        $f = new WebSocketFrame(WebSocketFrame::KIND_BINARY, "\x00\x01");
        self::assertSame(WebSocketFrame::KIND_BINARY, $f->kind);
        self::assertSame("\x00\x01", $f->text);
    }
}
