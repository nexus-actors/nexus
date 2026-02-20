<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Swoole\Transport;

use Closure;
use Monadial\Nexus\Cluster\Transport\Transport;
use Override;
use Swoole\Coroutine;
use Swoole\Coroutine\Socket;

/**
 * @psalm-api
 *
 * Unix domain socket transport for inter-worker IPC.
 *
 * Wire format: [4 bytes: payload length (network byte order)] [N bytes: payload]
 *
 * Each worker creates a server socket and connects as a client to all other workers.
 * Fully coroutine-friendly — non-blocking reads and writes.
 *
 * Optimizations:
 * - Read buffering: receives up to 65536 bytes at once and parses multiple
 *   frames from the buffer. Reduces read syscalls from 2N to ~N/batch_size.
 */
final class UnixSocketTransport implements Transport
{
    private const int READ_BUFFER_SIZE = 65536;

    private ?Socket $serverSocket = null;

    /** @var array<int, Socket> Worker ID -> client socket */
    private array $clients = [];

    /** @var ?Closure(string): void */
    private ?Closure $listener = null;

    private bool $closed = false;

    public function __construct(
        private readonly int $workerId,
        private readonly int $workerCount,
        private readonly string $socketDir,
    ) {}

    /**
     * Create and bind the server socket for this worker.
     * Call this before connectToPeers().
     */
    public function bind(): void
    {
        $socketPath = $this->socketPathFor($this->workerId);

        if (file_exists($socketPath)) {
            unlink($socketPath);
        }

        $this->serverSocket = new Socket(AF_UNIX, SOCK_STREAM, 0);
        $this->serverSocket->bind($socketPath);
        $this->serverSocket->listen(128);

        Coroutine::create(function (): void {
            while (!$this->closed && $this->serverSocket !== null) {
                /** @var Socket|false $conn */
                $conn = $this->serverSocket->accept(1.0);

                if ($conn === false) {
                    continue;
                }

                Coroutine::create(function () use ($conn): void {
                    $this->handleConnection($conn);
                });
            }
        });
    }

    /**
     * Connect to all other workers' server sockets.
     * Call after all workers have called bind().
     */
    public function connectToPeers(): void
    {
        for ($i = 0; $i < $this->workerCount; $i++) {
            if ($i === $this->workerId) {
                continue;
            }

            $socketPath = $this->socketPathFor($i);
            $client = new Socket(AF_UNIX, SOCK_STREAM, 0);
            $client->connect($socketPath);
            $this->clients[$i] = $client;
        }
    }

    #[Override]
    public function send(int $targetWorker, string $data): void
    {
        $client = $this->clients[$targetWorker] ?? null;

        if ($client === null) {
            return;
        }

        $frame = pack('N', strlen($data)) . $data;
        $client->sendAll($frame);
    }

    #[Override]
    public function listen(callable $onMessage): void
    {
        $this->listener = $onMessage(...);
    }

    #[Override]
    public function close(): void
    {
        $this->closed = true;

        foreach ($this->clients as $client) {
            $client->close();
        }

        $this->clients = [];

        if ($this->serverSocket !== null) {
            $this->serverSocket->close();
            $this->serverSocket = null;
        }

        $socketPath = $this->socketPathFor($this->workerId);

        if (file_exists($socketPath)) {
            @unlink($socketPath);
        }

        $this->listener = null;
    }

    /**
     * Read buffered: receives large chunks and parses multiple frames.
     */
    private function handleConnection(Socket $conn): void
    {
        $buffer = '';

        while (!$this->closed) {
            /** @var string|false $chunk */
            $chunk = $conn->recv(self::READ_BUFFER_SIZE, 1.0);

            if ($chunk === '' || $chunk === false) {
                if ($buffer !== '') {
                    continue; // Partial frame in buffer, wait for more data
                }

                break;
            }

            $buffer .= $chunk;

            // Parse all complete frames from the buffer
            while (strlen($buffer) >= 4) {
                /** @var array{1: int} $unpacked */
                $unpacked = unpack('N', $buffer);
                $len = $unpacked[1];

                if (strlen($buffer) < 4 + $len) {
                    break; // Incomplete frame, wait for more data
                }

                $data = substr($buffer, 4, $len);
                $buffer = substr($buffer, 4 + $len);

                if ($this->listener !== null) {
                    ($this->listener)($data);
                }
            }
        }

        $conn->close();
    }

    private function socketPathFor(int $workerId): string
    {
        return $this->socketDir . "/worker-{$workerId}.sock";
    }
}
