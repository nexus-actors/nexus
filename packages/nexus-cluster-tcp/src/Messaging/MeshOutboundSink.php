<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Messaging;

use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\EndpointResolver;
use Monadial\Nexus\Cluster\Tcp\Frame;
use Monadial\Nexus\Cluster\Tcp\FrameType;
use Monadial\Nexus\Cluster\Tcp\MeshTransport;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Cluster\Tcp\Payload\MessagePayload;
use Monadial\Nexus\Cluster\Tcp\PeerConnection;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Runtime\Runtime;
use Monadial\Nexus\Serialization\MessageSerializer;
use Override;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @psalm-api
 *
 * Transport-backed {@see OutboundSink} that maintains one {@see PeerConnection} per resolved
 * peer endpoint. Connections are created lazily on first send and reused thereafter.
 *
 * For each send:
 *   1. Resolve the target {@see NodeAddress} to a {@see NodeEndpoint} via the injected
 *      {@see EndpointResolver}.
 *   2. Serialize the {@see MessagePayload} to bytes using the injected payload serializer.
 *   3. Wrap the bytes in a {@see FrameType::Message} frame and enqueue it on the peer's
 *      {@see PeerConnection} (which handles buffering and reconnect automatically).
 *
 * Unresolvable endpoints are silently dropped and counted via {@see drops()}.
 */
final class MeshOutboundSink implements OutboundSink
{
    /** @var array<string, PeerConnection> */
    private array $connections = [];

    private int $drops = 0;

    public function __construct(
        private readonly EndpointResolver $resolver,
        private readonly MeshTransport $transport,
        private readonly Runtime $runtime,
        private readonly MessageSerializer $payloadSerializer,
        private readonly Duration $reconnectInitialBackoff,
        private readonly Duration $reconnectMaxBackoff,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    #[Override]
    public function send(NodeAddress $target, MessagePayload $payload): void
    {
        $endpoint = $this->resolver->resolve($target);

        if ($endpoint === null) {
            ++$this->drops;
            $this->logger->debug('MeshOutboundSink: dropping message — no endpoint registered for node', [
                'target' => $target->toPathPrefix(),
            ]);

            return;
        }

        $connection = $this->getOrCreate($endpoint);
        $bytes = $this->payloadSerializer->serialize($payload);
        $connection->sendFrame(new Frame(FrameType::Message, $bytes));
    }

    /**
     * Close all peer connections. No-op on already-closed connections.
     */
    public function close(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }

        $this->connections = [];
    }

    /**
     * Number of messages dropped due to unresolvable endpoint.
     */
    public function drops(): int
    {
        return $this->drops;
    }

    private function getOrCreate(NodeEndpoint $endpoint): PeerConnection
    {
        $key = (string) $endpoint;

        if (!isset($this->connections[$key])) {
            $this->connections[$key] = new PeerConnection(
                $endpoint,
                $this->transport,
                $this->runtime,
                $this->reconnectInitialBackoff,
                $this->reconnectMaxBackoff,
            );
        }

        return $this->connections[$key];
    }
}
