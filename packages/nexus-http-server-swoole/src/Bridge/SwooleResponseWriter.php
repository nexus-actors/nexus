<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Bridge;

use Psr\Http\Message\ResponseInterface;
use Swoole\Http\Response as SwooleResponse;

/**
 * @psalm-api
 *
 * Writes a PSR-7 ResponseInterface to a Swoole\Http\Response. Streams bodies
 * with unknown size per chunk via $swoole->write(); buffered bodies use a
 * single end().
 *
 * 204/304 responses always go out with bare end() (no body, per spec).
 */
final class SwooleResponseWriter
{
    public static function write(ResponseInterface $psr7, SwooleResponse $swoole): void
    {
        $swoole->status($psr7->getStatusCode(), $psr7->getReasonPhrase());

        foreach ($psr7->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $swoole->header((string) $name, $value);
            }
        }

        $statusCode = $psr7->getStatusCode();

        if ($statusCode === 204 || $statusCode === 304) {
            $swoole->end();

            return;
        }

        $body = $psr7->getBody();

        if (SwooleStreamingDetector::isStreaming($body)) {
            while (!$body->eof()) {
                $chunk = $body->read(8192);

                if ($chunk === '') {
                    break;
                }

                $swoole->write($chunk);
            }

            $swoole->end();

            return;
        }

        $swoole->end((string) $body);
    }
}
