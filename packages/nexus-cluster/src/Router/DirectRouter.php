<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Router;

use Monadial\Nexus\Cluster\Transport\EnvelopeTransport;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Override;
use RuntimeException;

/**
 * @psalm-api
 *
 * Routes envelopes directly via envelope-oriented transport (no serialization).
 * Wraps an EnvelopeTransport for shared-memory communication (e.g., Swoole threads).
 */
final class DirectRouter implements MessageRouter
{
    private bool $closed = false;

    public function __construct(private readonly EnvelopeTransport $transport) {}

    #[Override]
    public function send(int $targetWorker, Envelope $envelope): void
    {
        $this->transport->send($targetWorker, $envelope);
    }

    #[Override]
    public function startReceiving(callable $handler): void
    {
        while (!$this->closed) {
            try {
                $envelope = $this->transport->receive();
                $handler($envelope);
            } catch (RuntimeException) {
                break;
            }
        }
    }

    #[Override]
    public function close(): void
    {
        $this->closed = true;
        $this->transport->close();
    }
}
