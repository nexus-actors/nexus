<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Toolkit\Middleware;

use Monadial\Nexus\Logger\Mdc;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function bin2hex;
use function class_exists;
use function preg_match;
use function random_bytes;

/**
 * @psalm-api
 *
 * W3C Trace Context middleware. Parses the inbound `traceparent` header
 * (per https://www.w3.org/TR/trace-context/), pushes the trace/span IDs
 * into MDC so every downstream log line carries them, generates a fresh
 * trace if the header is missing or malformed, and writes a new
 * `traceparent` onto the response so callers/sidecars can correlate.
 *
 * Format: `00-{32 hex}-{16 hex}-{2 hex}`
 *   - version: 00 (only one defined)
 *   - trace-id: 16 random bytes (32 hex), preserved across the request
 *   - span-id:  8 random bytes (16 hex), this hop's span
 *   - flags:    bitfield (01 = sampled)
 *
 * Three request attributes are set so downstream code can read them
 * without re-parsing:
 *   - trace.id
 *   - trace.parentSpanId  (the inbound span, this hop's parent)
 *   - trace.spanId        (this hop's span)
 *
 * MDC keys: traceId, spanId, parentSpanId.
 *
 * If nexus-logger is not installed, MDC writes are skipped silently
 * (the middleware still sets request attributes and the response
 * header). Soft-depends nexus-actors/logger.
 */
final class TraceContextMiddleware implements MiddlewareInterface
{
    private const string TRACEPARENT_HEADER = 'traceparent';

    private const string TRACEPARENT_REGEX = '/^00-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/i';

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        [$traceId, $parentSpanId, $flags] = $this->parseInbound($request);
        $spanId = bin2hex(random_bytes(8));

        $request = $request
            ->withAttribute('trace.id', $traceId)
            ->withAttribute('trace.parentSpanId', $parentSpanId)
            ->withAttribute('trace.spanId', $spanId);

        if (class_exists(Mdc::class)) {
            Mdc::put('traceId', $traceId);
            Mdc::put('spanId', $spanId);

            if ($parentSpanId !== '') {
                Mdc::put('parentSpanId', $parentSpanId);
            }
        }

        $response = $handler->handle($request);

        return $response->withHeader(
            self::TRACEPARENT_HEADER,
            sprintf('00-%s-%s-%s', $traceId, $spanId, $flags),
        );
    }

    /**
     * @return array{0: string, 1: string, 2: string}  [traceId, parentSpanId, flags]
     */
    private function parseInbound(ServerRequestInterface $request): array
    {
        $header = $request->getHeaderLine(self::TRACEPARENT_HEADER);

        if ($header === '' || preg_match(self::TRACEPARENT_REGEX, $header, $m) !== 1) {
            return [bin2hex(random_bytes(16)), '', '01'];
        }

        return [$m[1], $m[2], $m[3]];
    }
}
