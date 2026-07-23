<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Protocol;

use Monadial\Nexus\Cluster\Tcp\Payload\ControlFrameCodec;
use Monadial\Nexus\Cluster\Tcp\Payload\GossipPayload;
use Monadial\Nexus\Cluster\Tcp\Payload\Handshake;
use Monadial\Nexus\Cluster\Tcp\Payload\HandshakeAck;
use Monadial\Nexus\Cluster\Tcp\Payload\LeavePayload;
use Monadial\Nexus\Cluster\Tcp\Payload\MessagePayload;
use Monadial\Nexus\Cluster\Tcp\Payload\MessagePayloadCodec;
use Monadial\Nexus\Serialization\Msgpack\MsgpackCodec;

/**
 * Default wire format: exactly the hand-rolled, perf-tuned msgpack codecs
 * grouped behind the WireFormat seam — zero hot-path change (spec §3.4.4).
 */
final readonly class MsgpackWireFormat implements WireFormat
{
    private ControlFrameCodec $control;
    private MessagePayloadCodec $message;

    public function __construct(MsgpackCodec $codec = new MsgpackCodec())
    {
        $this->control = new ControlFrameCodec($codec);
        $this->message = new MessagePayloadCodec($codec);
    }

    #[\Override]
    public function packHandshake(Handshake $handshake): string
    {
        return $this->control->packHandshake($handshake);
    }

    #[\Override]
    public function unpackHandshake(string $bytes): Handshake
    {
        return $this->control->unpackHandshake($bytes);
    }

    #[\Override]
    public function packHandshakeAck(HandshakeAck $ack): string
    {
        return $this->control->packHandshakeAck($ack);
    }

    #[\Override]
    public function unpackHandshakeAck(string $bytes): HandshakeAck
    {
        return $this->control->unpackHandshakeAck($bytes);
    }

    #[\Override]
    public function packGossip(GossipPayload $gossip): string
    {
        return $this->control->packGossip($gossip);
    }

    #[\Override]
    public function unpackGossip(string $bytes): GossipPayload
    {
        return $this->control->unpackGossip($bytes);
    }

    #[\Override]
    public function packLeave(LeavePayload $leave): string
    {
        return $this->control->packLeave($leave);
    }

    #[\Override]
    public function unpackLeave(string $bytes): LeavePayload
    {
        return $this->control->unpackLeave($bytes);
    }

    #[\Override]
    public function packMessage(MessagePayload $payload): string
    {
        return $this->message->pack($payload);
    }

    #[\Override]
    public function unpackMessage(string $bytes): MessagePayload
    {
        return $this->message->unpack($bytes);
    }
}
