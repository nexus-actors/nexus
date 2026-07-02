<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Performance\HttpSwoole\Support;

use RuntimeException;

use function fclose;
use function stream_socket_get_name;
use function stream_socket_server;
use function strrpos;
use function substr;

/**
 * Discovers a free TCP port by binding ephemeral and reading back the assigned
 * port. Identical mechanism to ForkedSwooleServerFixture::findFreePort(), kept
 * separate so perf tests don't reach into integration test support.
 *
 * @psalm-api
 */
final class FreePort
{
    public static function find(string $host = '127.0.0.1'): int
    {
        $server = @stream_socket_server("tcp://{$host}:0");

        if ($server === false) {
            throw new RuntimeException('Failed to bind ephemeral TCP socket for free-port discovery');
        }

        $name = stream_socket_get_name($server, false);
        fclose($server);

        if ($name === false) {
            throw new RuntimeException('Failed to read free port name');
        }

        $colon = strrpos($name, ':');

        if ($colon === false) {
            throw new RuntimeException("Malformed bound address: {$name}");
        }

        return (int) substr($name, $colon + 1);
    }
}
