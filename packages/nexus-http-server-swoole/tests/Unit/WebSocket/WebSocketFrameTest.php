<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Tests\Unit\WebSocket;

use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketFrame;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(WebSocketFrame::class)]
final class WebSocketFrameTest extends TestCase
{
    #[Test]
    public function textConstructor(): void
    {
        $f = WebSocketFrame::text('hello');

        self::assertTrue($f->isText());
        self::assertFalse($f->isBinary());
        self::assertSame('hello', $f->text);
    }

    #[Test]
    public function binaryConstructor(): void
    {
        $f = WebSocketFrame::binary("\x01\x02");

        self::assertTrue($f->isBinary());
        self::assertSame("\x01\x02", $f->text);
    }
}
