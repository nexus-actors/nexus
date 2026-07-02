<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Bridge;

use Psr\Http\Message\StreamInterface;

/**
 * @psalm-api
 *
 * Decides whether a PSR-7 response body should be streamed (per-chunk writes)
 * vs sent in a single end() call.
 *
 * Heuristic: getSize() === null means the stream's total length isn't known
 * up front — that's the signal a producer is yielding chunks lazily
 * (IteratorStream, generator-backed bodies). Concrete file/string streams
 * always report a size.
 */
final class SwooleStreamingDetector
{
    public static function isStreaming(StreamInterface $body): bool
    {
        return $body->getSize() === null;
    }
}
