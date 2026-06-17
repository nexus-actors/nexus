<?php

declare(strict_types=1);

namespace Monadial\Nexus\WorkerPool\Transport;

use Monadial\Nexus\Core\Mailbox\Envelope;

/**
 * @psalm-api
 *
 * Envelope-based inter-worker transport.
 * Implementations: InMemoryWorkerTransport (testing), ThreadQueueTransport (Swoole threads).
 */
interface WorkerTransport
{
    /**
     * Send an envelope to the target worker.
     */
    public function send(int $targetWorker, Envelope $envelope): void;

    /**
     * Register a listener for incoming envelopes.
     *
     * @param callable(Envelope): void $onEnvelope
     */
    public function listen(callable $onEnvelope): void;

    /**
     * Close the transport and release resources.
     */
    public function close(): void;

    /**
     * Signal the transport to stop. The receive loop exits cooperatively
     * on its next backoff wakeup (within ~10ms in the worst case).
     *
     * Idempotent — calling stop() on an already-stopped transport is a no-op.
     *
     * Required for graceful shutdown: without stop(), the receive loop blocks
     * indefinitely and the worker cannot exit cleanly.
     */
    public function stop(): void;

    /**
     * Whether stop() has been called on this transport.
     */
    public function isStopped(): bool;
}
