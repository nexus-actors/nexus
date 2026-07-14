<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Payload;

use Monadial\Nexus\Serialization\MessageType;

/**
 * @psalm-api
 *
 * Response to a Handshake frame. When `accepted` is false, `reason` explains
 * the rejection (e.g. cluster name mismatch). `view` is a snapshot of the
 * acceptor's current cluster view: node path-prefix → advertise endpoint.
 */
#[MessageType('cluster.handshake_ack')]
final readonly class HandshakeAck
{
    /**
     * @param array<string, string> $view Node path-prefix → host:port advertise endpoint.
     */
    public function __construct(public bool $accepted, public ?string $reason, public array $view) {}
}
