<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Unit\Protocol;

use Monadial\Nexus\Cluster\Tcp\Payload\ControlFrameCodec;
use Monadial\Nexus\Cluster\Tcp\Payload\GossipPayload;
use Monadial\Nexus\Cluster\Tcp\Payload\Handshake;
use Monadial\Nexus\Cluster\Tcp\Payload\HandshakeAck;
use Monadial\Nexus\Cluster\Tcp\Payload\LeavePayload;
use Monadial\Nexus\Cluster\Tcp\Payload\MessagePayload;
use Monadial\Nexus\Cluster\Tcp\Payload\MessagePayloadCodec;
use Monadial\Nexus\Cluster\Tcp\Protocol\MsgpackWireFormat;
use Monadial\Nexus\Cluster\Tcp\Protocol\WireFormat;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MsgpackWireFormat::class)]
final class MsgpackWireFormatTest extends TestCase
{
    private MsgpackWireFormat $wire;

    #[Test]
    public function implementsWireFormat(): void
    {
        self::assertInstanceOf(WireFormat::class, $this->wire);
    }

    #[Test]
    public function handshakeRoundTripsByteIdenticalToControlFrameCodec(): void
    {
        $handshake = new Handshake(
            clusterName: 'prod',
            node: ['application' => 'nexus', 'cluster' => 'prod', 'datacenter' => 'dc1', 'node' => 'node-1'],
            advertise: '127.0.0.1:7361',
            protocolVersion: 1,
            nonce: 'abc',
            issuedAt: 1234,
            mac: 'sig',
        );

        self::assertSame((new ControlFrameCodec())->packHandshake($handshake), $this->wire->packHandshake($handshake));
        self::assertEquals($handshake, $this->wire->unpackHandshake($this->wire->packHandshake($handshake)));
    }

    #[Test]
    public function handshakeAckRoundTrips(): void
    {
        $ack = new HandshakeAck(true, null, ['/cluster/prod/dc1/nexus/node-2' => '10.0.0.2:7361']);

        self::assertSame((new ControlFrameCodec())->packHandshakeAck($ack), $this->wire->packHandshakeAck($ack));
        self::assertEquals($ack, $this->wire->unpackHandshakeAck($this->wire->packHandshakeAck($ack)));
    }

    #[Test]
    public function gossipRoundTrips(): void
    {
        $gossip = new GossipPayload(
            [
                ['address' => '/cluster/prod/dc1/nexus/node-1', 'endpoint' => '10.0.0.1:7361', 'incarnation' => 3, 'status' => 1],
            ],
            [],
        );

        self::assertSame((new ControlFrameCodec())->packGossip($gossip), $this->wire->packGossip($gossip));
        self::assertEquals($gossip, $this->wire->unpackGossip($this->wire->packGossip($gossip)));
    }

    #[Test]
    public function leaveRoundTrips(): void
    {
        $leave = new LeavePayload('/cluster/prod/dc1/nexus/node-1');

        self::assertSame((new ControlFrameCodec())->packLeave($leave), $this->wire->packLeave($leave));
        self::assertEquals($leave, $this->wire->unpackLeave($this->wire->packLeave($leave)));
    }

    #[Test]
    public function messageRoundTripsByteIdenticalToMessagePayloadCodec(): void
    {
        $payload = new MessagePayload(
            targetPath: '/user/orders',
            messageType: 'demo.ping',
            body: '{"n":1}',
            correlationId: 'c-1',
            replyPath: '/user/reply',
            trace: ['traceparent' => '00-abc-def-01'],
        );

        self::assertSame((new MessagePayloadCodec())->pack($payload), $this->wire->packMessage($payload));
        self::assertEquals($payload, $this->wire->unpackMessage($this->wire->packMessage($payload)));
    }

    protected function setUp(): void
    {
        $this->wire = new MsgpackWireFormat();
    }
}
