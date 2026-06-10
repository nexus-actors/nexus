<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Response;

use Monadial\Nexus\Http\Response\StreamingResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(StreamingResponse::class)]
final class StreamingResponseTest extends TestCase
{
    #[Test]
    public function from_generator_streams_chunks(): void
    {
        $gen = (static function () {
            yield 'a';
            yield 'b';
        })();

        $response = StreamingResponse::fromGenerator($gen, 200, ['X-Custom' => 'yes']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('yes', $response->getHeaderLine('X-Custom'));
        self::assertSame('ab', (string) $response->getBody());
    }

    #[Test]
    public function ndjson_serializes_each_item_with_newline(): void
    {
        $response = StreamingResponse::ndjson([
            ['id' => 1, 'name' => 'a'],
            ['id' => 2, 'name' => 'b'],
        ]);

        self::assertSame('application/x-ndjson', $response->getHeaderLine('Content-Type'));
        self::assertSame(
            "{\"id\":1,\"name\":\"a\"}\n{\"id\":2,\"name\":\"b\"}\n",
            (string) $response->getBody(),
        );
    }

    #[Test]
    public function sse_formats_events_in_sse_protocol(): void
    {
        $response = StreamingResponse::sse([
            ['event' => 'message', 'data' => 'hello'],
            ['data' => 'world'],
        ]);

        self::assertSame('text/event-stream', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-cache', $response->getHeaderLine('Cache-Control'));
        self::assertSame(
            "event: message\ndata: hello\n\ndata: world\n\n",
            (string) $response->getBody(),
        );
    }
}
