<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Tests\Unit\Bridge;

use Monadial\Nexus\Http\Response\IteratorStream;
use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Http\Server\Swoole\Bridge\SwooleResponseWriter;
use Nyholm\Psr7\Response as Psr7Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Http\Response as SwooleResponse;

#[CoversClass(SwooleResponseWriter::class)]
final class SwooleResponseWriterTest extends TestCase
{
    #[Test]
    public function writes_status_headers_and_body(): void
    {
        $fake = $this->fakeResponse();
        $psr7 = new Psr7Response(200, ['Content-Type' => 'text/plain'], 'hello');

        SwooleResponseWriter::write($psr7, $fake);

        self::assertSame(200, $fake->statusCode);
        self::assertSame('text/plain', $fake->headers['Content-Type'][0]);
        self::assertTrue($fake->ended);
        self::assertSame('hello', $fake->endBody);
        self::assertSame([], $fake->chunks);
    }

    #[Test]
    public function noContent_204_omits_body(): void
    {
        $fake = $this->fakeResponse();
        SwooleResponseWriter::write(Response::noContent(), $fake);

        self::assertSame(204, $fake->statusCode);
        self::assertTrue($fake->ended);
        self::assertNull($fake->endBody);
    }

    #[Test]
    public function streaming_body_is_written_per_chunk(): void
    {
        $iter = (static function () {
            yield 'one';
            yield 'two';
            yield 'three';
        })();

        $stream = new IteratorStream($iter);
        $psr7 = (new Psr7Response(200))->withBody($stream);

        $fake = $this->fakeResponse();
        SwooleResponseWriter::write($psr7, $fake);

        self::assertSame(['one', 'two', 'three'], $fake->chunks);
        self::assertTrue($fake->ended);
        self::assertNull($fake->endBody);
    }

    private function fakeResponse(): SwooleResponse
    {
        return new class extends SwooleResponse {
            public int $statusCode = 0;

            /** @var array<string, list<string>> */
            public array $headers = [];

            /** @var list<string> */
            public array $chunks = [];

            public ?string $endBody = null;

            public bool $ended = false;

            public function status(int|string $http_code, string $reason = ''): bool
            {
                $this->statusCode = (int) $http_code;

                return true;
            }

            public function header(string $key, string|array $value, bool $format = true): bool
            {
                $this->headers[$key][] = is_array($value)
                    ? implode(',', $value)
                    : $value;

                return true;
            }

            public function write(mixed $data): bool
            {
                $this->chunks[] = (string) $data;

                return true;
            }

            public function end(mixed $content = ''): bool
            {
                if ((string) $content !== '') {
                    $this->endBody = (string) $content;
                }

                $this->ended = true;

                return true;
            }
        };
    }
}
