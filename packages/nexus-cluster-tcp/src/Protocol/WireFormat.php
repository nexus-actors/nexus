<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Protocol;

use Monadial\Nexus\Cluster\Tcp\Payload\GossipPayload;
use Monadial\Nexus\Cluster\Tcp\Payload\Handshake;
use Monadial\Nexus\Cluster\Tcp\Payload\HandshakeAck;
use Monadial\Nexus\Cluster\Tcp\Payload\LeavePayload;
use Monadial\Nexus\Cluster\Tcp\Payload\MessagePayload;
use Monadial\Nexus\Serialization\Exception\MessageDeserializationException;
use Monadial\Nexus\Serialization\Exception\MessageSerializationException;

/**
 * Codec family for cluster protocol payloads (handshake, gossip, leave,
 * message envelope) — the wire-format seam of the transport SPI (design spec
 * §3.4.4). Framing (FrameCodec) is deliberately NOT part of this contract:
 * framing belongs to the transport, wire format to payload encoding.
 *
 * Implementations must preserve forward compatibility: unpack* resolves
 * fields by key with defaults and ignores unknown keys. User message BODIES
 * are encoded by the orthogonal MessageSerializer, not by the wire format.
 */
interface WireFormat
{
    /** @throws MessageSerializationException */
    public function packHandshake(Handshake $handshake): string;

    /** @throws MessageDeserializationException */
    public function unpackHandshake(string $bytes): Handshake;

    /** @throws MessageSerializationException */
    public function packHandshakeAck(HandshakeAck $ack): string;

    /** @throws MessageDeserializationException */
    public function unpackHandshakeAck(string $bytes): HandshakeAck;

    /** @throws MessageSerializationException */
    public function packGossip(GossipPayload $gossip): string;

    /** @throws MessageDeserializationException */
    public function unpackGossip(string $bytes): GossipPayload;

    /** @throws MessageSerializationException */
    public function packLeave(LeavePayload $leave): string;

    /** @throws MessageDeserializationException */
    public function unpackLeave(string $bytes): LeavePayload;

    /** @throws MessageSerializationException */
    public function packMessage(MessagePayload $payload): string;

    /** @throws MessageDeserializationException */
    public function unpackMessage(string $bytes): MessagePayload;
}
