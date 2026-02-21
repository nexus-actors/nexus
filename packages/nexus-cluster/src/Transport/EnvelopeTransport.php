<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Transport;

use Monadial\Nexus\Core\Mailbox\Envelope;
use NoDiscard;

/**
 * @psalm-api
 *
 * Envelope-level inter-worker transport (no serialization required).
 * Implementations: InMemoryEnvelopeTransport (testing), ThreadQueueTransport (Swoole threads).
 */
interface EnvelopeTransport
{
    /**
     * Send an envelope to a target worker.
     */
    public function send(int $targetWorker, Envelope $envelope): void;

    /**
     * Receive the next envelope (blocking).
     */
    #[NoDiscard]
    public function receive(): Envelope;

    /**
     * Close the transport and release resources.
     */
    public function close(): void;
}
