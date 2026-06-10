<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Response;

use ArrayIterator;
use Closure;
use Generator;
use Iterator;
use IteratorAggregate;
use Nyholm\Psr7\Response as Psr7Response;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

use function filesize;
use function fopen;
use function is_array;
use function is_readable;
use function iterator_to_array;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * @psalm-api
 *
 * Static factories for streaming responses. The body is an IteratorStream that
 * pulls chunks per read(). Server adapters MUST honour read+flush per chunk
 * for SSE/NDJSON to deliver incrementally.
 */
final class StreamingResponse
{
    public static function file(string $path, ?string $contentType = null): ResponseInterface
    {
        if (!is_readable($path)) {
            throw new RuntimeException("File not readable: {$path}");
        }

        $headers = ['Content-Length' => (string) filesize($path)];

        if ($contentType !== null) {
            $headers['Content-Type'] = $contentType;
        }

        $resource = fopen($path, 'rb');

        if ($resource === false) {
            throw new RuntimeException("Failed to open file: {$path}");
        }

        return new Psr7Response(200, $headers, Stream::create($resource));
    }

    /**
     * @param array<string, string> $headers
     */
    public static function fromGenerator(Generator $chunks, int $status = 200, array $headers = []): ResponseInterface
    {
        return (new Psr7Response($status, $headers))
            ->withBody(new IteratorStream($chunks));
    }

    /**
     * Each item becomes one newline-delimited JSON object.
     *
     * @param iterable<mixed> $items
     * @param (Closure(mixed): string)|null $encoder Custom encoder. Defaults to json_encode.
     *
     * @psalm-suppress MixedAssignment ndjson accepts heterogeneous items by design
     */
    public static function ndjson(iterable $items, ?Closure $encoder = null): ResponseInterface
    {
        $encoder ??= static fn(mixed $item): string => json_encode(
            $item,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        $iterator = self::toIterator($items);
        $chunks = (static function () use ($iterator, $encoder): Generator {
            foreach ($iterator as $item) {
                yield $encoder($item) . "\n";
            }
        })();

        return (new Psr7Response(200, ['Content-Type' => 'application/x-ndjson']))
            ->withBody(new IteratorStream($chunks));
    }

    /**
     * Server-Sent Events. Each event is an array with keys: id?, event?, data, retry?.
     *
     * @param iterable<array{data: string, event?: string, id?: string, retry?: int}> $events
     */
    public static function sse(iterable $events): ResponseInterface
    {
        $iterator = self::toIterator($events);
        $chunks = (static function () use ($iterator): Generator {
            foreach ($iterator as $event) {
                $out = '';

                if (isset($event['id'])) {
                    $out .= "id: {$event['id']}\n";
                }

                if (isset($event['event'])) {
                    $out .= "event: {$event['event']}\n";
                }

                if (isset($event['retry'])) {
                    $out .= "retry: {$event['retry']}\n";
                }

                $out .= "data: {$event['data']}\n\n";
                yield $out;
            }
        })();

        return (new Psr7Response(200, [
            'Cache-Control' => 'no-cache',
            'Content-Type' => 'text/event-stream',
        ]))->withBody(new IteratorStream($chunks));
    }

    /**
     * @template TValue
     *
     * @param iterable<TValue> $items
     *
     * @return Iterator<TValue>
     */
    private static function toIterator(iterable $items): Iterator
    {
        if ($items instanceof Iterator) {
            return $items;
        }

        if ($items instanceof IteratorAggregate) {
            /** @var Iterator<TValue> */
            return $items->getIterator();
        }

        return new ArrayIterator(is_array($items) ? $items : iterator_to_array($items));
    }
}
