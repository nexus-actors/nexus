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
 */
final class UnixSocketTransport implements Transport
{
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

    private function handleConnection(Socket $conn): void
    {
        while (!$this->closed) {
            /** @var string|false $lenBytes */
            $lenBytes = $conn->recvAll(4, 1.0);

            if ($lenBytes === '' || $lenBytes === false || strlen($lenBytes) < 4) {
                break;
            }

            /** @var array{1: int} $unpacked */
            $unpacked = unpack('N', $lenBytes);
            $len = $unpacked[1];

            /** @var string|false $data */
            $data = $conn->recvAll($len, 5.0);

            if ($data === '' || $data === false || strlen($data) < $len) {
                break;
            }

            if ($this->listener !== null) {
                ($this->listener)($data);
            }
        }

        $conn->close();
    }

    private function socketPathFor(int $workerId): string
    {
        return $this->socketDir . "/worker-{$workerId}.sock";
    }
}
