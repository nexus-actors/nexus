<?php
declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Transport;

/**
 * @psalm-api
 *
 * Inter-worker message transport.
 * Implementations: InMemoryTransport (testing), UnixSocketTransport (production).
 */
interface Transport
{
    /**
     * Send serialized data to a target worker.
     */
    public function send(int $targetWorker, string $data): void;

    /**
     * Register a listener for incoming messages.
     *
     * @param callable(string): void $onMessage Called with raw message data
     */
    public function listen(callable $onMessage): void;

    /**
     * Close the transport and release resources.
     */
    public function close(): void;
}
