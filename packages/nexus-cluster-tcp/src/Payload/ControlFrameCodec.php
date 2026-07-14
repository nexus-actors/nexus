<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Payload;

use Monadial\Nexus\Serialization\Msgpack\MsgpackCodec;

/**
 * @psalm-api
 *
 * The cluster's binary control-protocol codec: hand-rolled MessagePack for the four wire frames the
 * mesh itself exchanges (Handshake, HandshakeAck, Gossip, Leave). These are on the hot path — gossip
 * doubles as the heartbeat and is sent to every Up peer each interval — so they deliberately do NOT
 * go through the generic Valinor-backed serializer, whose reflection-driven object mapping is pure
 * overhead for a small, fixed, internally-owned set of VOs. (Valinor remains the right tool for
 * arbitrary USER message bodies, which flow through {@see \Monadial\Nexus\Cluster\Tcp\Messaging\ClusterMessageCodec}.)
 *
 * Each frame is a msgpack map keyed by property name, so the bytes are identical to the previous
 * Valinor encoding and readers resolve fields by key with defaults — nodes on either encoder interop,
 * and a newer protocol version can add a field without breaking an older decoder.
 */
final readonly class ControlFrameCodec
{
    private HandshakeCodec $handshakeCodec;

    private HandshakeAckCodec $handshakeAckCodec;

    private GossipPayloadCodec $gossipCodec;

    private LeavePayloadCodec $leaveCodec;

    public function __construct(MsgpackCodec $codec = new MsgpackCodec())
    {
        $this->handshakeCodec = new HandshakeCodec($codec);
        $this->handshakeAckCodec = new HandshakeAckCodec($codec);
        $this->gossipCodec = new GossipPayloadCodec($codec);
        $this->leaveCodec = new LeavePayloadCodec($codec);
    }

    public function packHandshake(Handshake $handshake): string
    {
        return $this->handshakeCodec->pack($handshake);
    }

    public function unpackHandshake(string $bytes): Handshake
    {
        return $this->handshakeCodec->unpack($bytes);
    }

    public function packHandshakeAck(HandshakeAck $ack): string
    {
        return $this->handshakeAckCodec->pack($ack);
    }

    public function unpackHandshakeAck(string $bytes): HandshakeAck
    {
        return $this->handshakeAckCodec->unpack($bytes);
    }

    public function packGossip(GossipPayload $gossip): string
    {
        return $this->gossipCodec->pack($gossip);
    }

    public function unpackGossip(string $bytes): GossipPayload
    {
        return $this->gossipCodec->unpack($bytes);
    }

    public function packLeave(LeavePayload $leave): string
    {
        return $this->leaveCodec->pack($leave);
    }

    public function unpackLeave(string $bytes): LeavePayload
    {
        return $this->leaveCodec->unpack($bytes);
    }
}
