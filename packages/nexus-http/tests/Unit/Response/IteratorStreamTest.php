<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Response;

use Monadial\Nexus\Http\Response\IteratorStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IteratorStream::class)]
final class IteratorStreamTest extends TestCase
{
    #[Test]
    public function read_pulls_one_chunk_per_call(): void
    {
        $chunks = (static function () {
            yield 'one';
            yield 'two';
            yield 'three';
        })();

        $stream = new IteratorStream($chunks);

        self::assertSame('one', $stream->read(1024));
        self::assertSame('two', $stream->read(1024));
        self::assertSame('three', $stream->read(1024));
    }

    #[Test]
    public function read_returns_empty_when_iterator_exhausted(): void
    {
        $chunks = (static function () {
            yield 'only';
        })();

        $stream = new IteratorStream($chunks);
        $stream->read(1024);

        self::assertSame('', $stream->read(1024));
        self::assertTrue($stream->eof());
    }

    #[Test]
    public function get_contents_concatenates_remaining_chunks(): void
    {
        $chunks = (static function () {
            yield 'hello ';
            yield 'world';
        })();

        $stream = new IteratorStream($chunks);

        self::assertSame('hello world', $stream->getContents());
    }
}
