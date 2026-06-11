<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\HttpSwoole\Support;

use Closure;
use RuntimeException;
use Swoole\Process;

use function fclose;
use function microtime;
use function stream_socket_client;
use function stream_socket_get_name;
use function stream_socket_server;
use function strrpos;
use function substr;
use function usleep;

use const SIGKILL;
use const SIGTERM;

/**
 * @psalm-api
 *
 * Reusable fixture for spawning a Swoole HTTP server in a child process for
 * integration tests. Polls TCP connect until the server is listening, then
 * returns control to the test thread. shutdown() sends SIGTERM and waits.
 *
 * pcntl_* is unavailable in the ZTS Swoole container, so this fixture uses
 * Swoole\Process. ext-sockets is not installed; free-port discovery uses
 * stream_socket_server('tcp://127.0.0.1:0').
 */
final class ForkedSwooleServerFixture
{
    private ?Process $process = null;

    public function __construct(private readonly string $host, private readonly int $port) {}

    /**
     * @param Closure(): void $body Child process body — boots and runs the
     *                              server, must call $server->start().
     */
    public function start(Closure $body): void
    {
        $proc = new Process($body);
        $pid  = $proc->start();

        if ($pid === false) {
            throw new RuntimeException('Failed to spawn child process');
        }

        $this->process = $proc;

        // Poll TCP until the child is listening, max 5s.
        $deadline = microtime(true) + 5.0;

        while (microtime(true) < $deadline) {
            $errno = 0;
            $errstr = '';
            $sock = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $errstr, 0.1);

            if ($sock !== false) {
                fclose($sock);

                return;
            }

            usleep(50_000);
        }

        $this->kill();

        throw new RuntimeException("Child server never bound to {$this->host}:{$this->port} within 5s");
    }

    public function shutdown(): void
    {
        if ($this->process === null) {
            return;
        }

        Process::kill($this->process->pid, SIGTERM);
        Process::wait(true);
        $this->process = null;
    }

    public function kill(): void
    {
        if ($this->process === null) {
            return;
        }

        Process::kill($this->process->pid, SIGKILL);
        Process::wait(true);
        $this->process = null;
    }

    public static function findFreePort(string $host = '127.0.0.1'): int
    {
        $server = @stream_socket_server("tcp://{$host}:0");

        if ($server === false) {
            throw new RuntimeException('Failed to find free port');
        }

        $name = stream_socket_get_name($server, false);
        fclose($server);

        if ($name === false) {
            throw new RuntimeException('Failed to read free port');
        }

        $colon = strrpos($name, ':');

        if ($colon === false) {
            throw new RuntimeException('Malformed address');
        }

        return (int) substr($name, $colon + 1);
    }
}
