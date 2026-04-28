<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Swoole\Tests\Unit;

use Monadial\Nexus\Http\Swoole\SwooleResponseEmitter;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Stand-in for Swoole\Http\Response. The emitter calls status/header/end on
 * the supplied response object — duck-typed via callable methods.
 */
final class FakeSwooleResponse
{
    public int $status = 0;

    /** @var array<string, string> */
    public array $headers = [];

    public string $body = '';

    public function status(int $code): bool
    {
        $this->status = $code;

        return true;
    }

    public function header(string $name, string $value): bool
    {
        $this->headers[$name] = $value;

        return true;
    }

    public function end(string $body = ''): bool
    {
        $this->body = $body;

        return true;
    }
}

#[CoversClass(SwooleResponseEmitter::class)]
final class SwooleResponseEmitterTest extends TestCase
{
    #[Test]
    public function emits_status_headers_and_body(): void
    {
        $psr = new Response(201, ['Content-Type' => 'application/json']);
        $psr = $psr->withBody(Stream::create('{"ok":true}'));

        $sw = new FakeSwooleResponse();
        (new SwooleResponseEmitter())->emit($psr, $sw);

        self::assertSame(201, $sw->status);
        self::assertSame('application/json', $sw->headers['Content-Type']);
        self::assertSame('{"ok":true}', $sw->body);
    }
}
