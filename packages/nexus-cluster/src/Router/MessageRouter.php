<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Router;

use Monadial\Nexus\Core\Mailbox\Envelope;

/**
 * @psalm-api
 *
 * Strategy for routing envelopes between cluster workers.
 * Implementations: SerializingRouter (byte-oriented transport), DirectRouter (envelope-oriented transport).
 */
interface MessageRouter
{
    /**
     * Send an envelope to a target worker.
     */
    public function send(int $targetWorker, Envelope $envelope): void;

    /**
     * Start receiving envelopes, passing each to the handler.
     *
     * @param callable(Envelope): void $handler
     */
    public function startReceiving(callable $handler): void;

    /**
     * Close the router and release resources.
     */
    public function close(): void;
}
