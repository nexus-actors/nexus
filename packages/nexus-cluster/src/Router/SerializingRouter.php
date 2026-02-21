<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Router;

use Monadial\Nexus\Cluster\Serialization\ClusterSerializer;
use Monadial\Nexus\Cluster\Transport\Transport;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Override;

/**
 * @psalm-api
 *
 * Routes envelopes via byte-oriented transport with serialization.
 * Wraps a Transport + ClusterSerializer pair for cross-process communication.
 */
final readonly class SerializingRouter implements MessageRouter
{
    public function __construct(private Transport $transport, private ClusterSerializer $serializer) {}

    #[Override]
    public function send(int $targetWorker, Envelope $envelope): void
    {
        $data = $this->serializer->serialize($envelope);
        $this->transport->send($targetWorker, $data);
    }

    #[Override]
    public function startReceiving(callable $handler): void
    {
        $this->transport->listen(function (string $data) use ($handler): void {
            $envelope = $this->serializer->deserialize($data);
            $handler($envelope);
        });
    }

    #[Override]
    public function close(): void
    {
        $this->transport->close();
    }
}
