<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Tests\Unit\Bridge;

use Monadial\Nexus\Http\Response\IteratorStream;
use Monadial\Nexus\Http\Server\Swoole\Bridge\SwooleStreamingDetector;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SwooleStreamingDetector::class)]
final class SwooleStreamingDetectorTest extends TestCase
{
    #[Test]
    public function iterator_stream_with_unknown_size_is_streaming(): void
    {
        $iter = (static function () { yield 'a'; })();
        self::assertTrue(SwooleStreamingDetector::isStreaming(new IteratorStream($iter)));
    }

    #[Test]
    public function fixed_string_stream_is_not_streaming(): void
    {
        self::assertFalse(SwooleStreamingDetector::isStreaming(Stream::create('hello')));
    }

    #[Test]
    public function empty_stream_is_not_streaming(): void
    {
        self::assertFalse(SwooleStreamingDetector::isStreaming(Stream::create('')));
    }
}
