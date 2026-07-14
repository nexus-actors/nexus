<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Messaging;

use Closure;
use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\Payload\MessagePayload;
use Override;

use function count;

/**
 * @psalm-api
 *
 * In-memory {@see OutboundSink} for loopback and unit tests. Records every sent
 * (target, payload) pair and, when an inbound callback is registered, feeds the payload
 * straight into it so a full ask round-trip can be exercised without a real socket.
 */
final class RecordingOutboundSink implements OutboundSink
{
    /** @var list<array{address: NodeAddress, payload: MessagePayload}> */
    private array $sent = [];

    /** @var Closure(NodeAddress, MessagePayload): void|null */
    private ?Closure $inbound = null;

    /**
     * Register a loopback sink invoked with (origin, payload) for each send, simulating
     * the payload arriving at the target node's inbound router.
     *
     * @param Closure(NodeAddress, MessagePayload): void $inbound
     */
    public function onInbound(Closure $inbound): void
    {
        $this->inbound = $inbound;
    }

    #[Override]
    public function send(NodeAddress $target, MessagePayload $payload): void
    {
        $this->sent[] = ['address' => $target, 'payload' => $payload];

        if ($this->inbound !== null) {
            ($this->inbound)($target, $payload);
        }
    }

    /**
     * @return list<array{address: NodeAddress, payload: MessagePayload}>
     */
    public function sent(): array
    {
        return $this->sent;
    }

    public function count(): int
    {
        return count($this->sent);
    }

    public function isEmpty(): bool
    {
        return $this->sent === [];
    }

    public function last(): ?MessagePayload
    {
        if ($this->sent === []) {
            return null;
        }

        return $this->sent[array_key_last($this->sent)]['payload'];
    }
}
