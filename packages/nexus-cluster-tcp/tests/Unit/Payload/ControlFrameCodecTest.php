<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Unit\Payload;

use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\ClusterTopology;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Cluster\Tcp\Payload\ControlFrameCodec;
use Monadial\Nexus\Cluster\Tcp\Payload\GossipPayload;
use Monadial\Nexus\Cluster\Tcp\Payload\Handshake;
use Monadial\Nexus\Cluster\Tcp\Payload\HandshakeAck;
use Monadial\Nexus\Cluster\Tcp\Payload\LeavePayload;
use Monadial\Nexus\Serialization\Exception\MessageDeserializationException;
use Monadial\Nexus\Serialization\Msgpack\MessagePackMessageSerializer;
use Monadial\Nexus\Serialization\Msgpack\MsgpackCodec;
use Monadial\Nexus\Serialization\TypeRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_merge;

#[CoversClass(ControlFrameCodec::class)]
final class ControlFrameCodecTest extends TestCase
{
    private ControlFrameCodec $codec;

    #[Test]
    public function handshakeRoundTrips(): void
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

        $decoded = $this->codec->unpackHandshake($this->codec->packHandshake($handshake));

        self::assertEquals($handshake, $decoded);
    }

    #[Test]
    public function handshakeAckRoundTrips(): void
    {
        $ack = new HandshakeAck(true, null, ['/cluster/prod/dc1/nexus/node-2' => '10.0.0.2:7361']);

        $decoded = $this->codec->unpackHandshakeAck($this->codec->packHandshakeAck($ack));

        self::assertEquals($ack, $decoded);
    }

    #[Test]
    public function gossipRoundTrips(): void
    {
        $gossip = new GossipPayload(
            [
                ['address' => '/cluster/prod/dc1/nexus/node-1', 'endpoint' => '10.0.0.1:7361', 'incarnation' => 3, 'status' => 1],
                ['address' => '/cluster/prod/dc1/nexus/node-2', 'endpoint' => '10.0.0.2:7361', 'incarnation' => 5, 'status' => 2],
            ],
            [],
        );

        $decoded = $this->codec->unpackGossip($this->codec->packGossip($gossip));

        self::assertEquals($gossip, $decoded);
    }

    #[Test]
    public function leaveRoundTrips(): void
    {
        $leave = new LeavePayload('/cluster/prod/dc1/nexus/node-1');

        $decoded = $this->codec->unpackLeave($this->codec->packLeave($leave));

        self::assertEquals($leave, $decoded);
    }

    #[Test]
    public function unpackIgnoresAnUnknownFutureField(): void
    {
        // Forward-compatibility: a frame a newer protocol version extended with an extra key must
        // still decode on an older node (by-key reads ignore unknown keys).
        $raw = new MsgpackCodec();
        $handshakeMap = $raw->unpack($this->codec->packHandshake(Handshake::forSelf(
            self::topology(),
        )));
        self::assertIsArray($handshakeMap);

        $withExtra = $raw->pack(array_merge($handshakeMap, ['futureV2Field' => 'ignored']));

        $decoded = $this->codec->unpackHandshake($withExtra);

        self::assertSame('prod', $decoded->clusterName);
    }

    #[Test]
    public function malformedFrameThrows(): void
    {
        $this->expectException(MessageDeserializationException::class);

        // A Handshake missing the required clusterName string.
        $bytes = (new MsgpackCodec())->pack(['advertise' => '127.0.0.1:7361']);

        $this->codec->unpackHandshake($bytes);
    }

    #[Test]
    public function wireFormatInteropsWithTheValinorSerializerBothWays(): void
    {
        // Rolling-upgrade guarantee: the hand-rolled codec and the previous Valinor-backed serializer
        // produce mutually-decodable msgpack, so a node on either encoder interops during an upgrade.
        $registry = new TypeRegistry();
        $registry->registerFromAttribute(Handshake::class);
        $valinor = new MessagePackMessageSerializer($registry);

        $handshake = Handshake::forSelf(self::topology());

        // Valinor decodes the hand-rolled bytes.
        $viaValinor = $valinor->deserialize($this->codec->packHandshake($handshake), 'cluster.handshake');
        self::assertEquals($handshake, $viaValinor);

        // The hand-rolled codec decodes Valinor's bytes.
        $viaCodec = $this->codec->unpackHandshake($valinor->serialize($handshake));
        self::assertEquals($handshake, $viaCodec);
    }

    #[Test]
    public function authenticatedHandshakeWireFormatInteropsWithTheValinorSerializerBothWays(): void
    {
        // forSelf() leaves nonce/issuedAt/mac null and protocolVersion at its default, so the plain
        // interop test never cross-decodes the authenticated path — the shared-secret handshake that
        // populates nonce/mac and the only nullable-int field (issuedAt, the value most prone to an
        // int-vs-string encoding quirk). Cross-decode a fully-populated authenticated Handshake.
        $registry = new TypeRegistry();
        $registry->registerFromAttribute(Handshake::class);
        $valinor = new MessagePackMessageSerializer($registry);

        $handshake = new Handshake(
            clusterName: 'prod',
            node: ['application' => 'nexus', 'cluster' => 'prod', 'datacenter' => 'dc1', 'node' => 'node-1'],
            advertise: '127.0.0.1:7361',
            protocolVersion: 2,
            nonce: 'a1b2c3d4',
            issuedAt: 1_700_000_000,
            mac: 'deadbeefsignature',
        );

        // Valinor decodes the hand-rolled bytes.
        $viaValinor = $valinor->deserialize($this->codec->packHandshake($handshake), 'cluster.handshake');
        self::assertEquals($handshake, $viaValinor);

        // The hand-rolled codec decodes Valinor's bytes.
        $viaCodec = $this->codec->unpackHandshake($valinor->serialize($handshake));
        self::assertEquals($handshake, $viaCodec);
    }

    #[Test]
    public function handshakeAckWireFormatInteropsWithTheValinorSerializerBothWays(): void
    {
        // Rolling-upgrade guarantee: the hand-rolled codec and the previous Valinor-backed serializer
        // produce mutually-decodable msgpack, so a node on either encoder interops during an upgrade.
        $registry = new TypeRegistry();
        $registry->registerFromAttribute(HandshakeAck::class);
        $valinor = new MessagePackMessageSerializer($registry);

        $ack = new HandshakeAck(true, null, ['/cluster/prod/dc1/nexus/node-2' => '10.0.0.2:7361']);

        // Valinor decodes the hand-rolled bytes.
        $viaValinor = $valinor->deserialize($this->codec->packHandshakeAck($ack), 'cluster.handshake_ack');
        self::assertEquals($ack, $viaValinor);

        // The hand-rolled codec decodes Valinor's bytes.
        $viaCodec = $this->codec->unpackHandshakeAck($valinor->serialize($ack));
        self::assertEquals($ack, $viaCodec);
    }

    #[Test]
    public function gossipWireFormatInteropsWithTheValinorSerializerBothWays(): void
    {
        // Rolling-upgrade guarantee: the hand-rolled codec and the previous Valinor-backed serializer
        // produce mutually-decodable msgpack, so a node on either encoder interops during an upgrade.
        // Two members of mixed status plus a non-empty registration map exercise both the member-list
        // and map encoding on the wire.
        $registry = new TypeRegistry();
        $registry->registerFromAttribute(GossipPayload::class);
        $valinor = new MessagePackMessageSerializer($registry);

        $gossip = new GossipPayload(
            [
                ['address' => '/cluster/prod/dc1/nexus/node-1', 'endpoint' => '10.0.0.1:7361', 'incarnation' => 3, 'status' => 1],
                ['address' => '/cluster/prod/dc1/nexus/node-2', 'endpoint' => '10.0.0.2:7361', 'incarnation' => 5, 'status' => 2],
            ],
            [
                ['name' => 'orders', 'path' => '/cluster/prod/dc1/nexus/orders'],
            ],
        );

        // Valinor decodes the hand-rolled bytes.
        $viaValinor = $valinor->deserialize($this->codec->packGossip($gossip), 'cluster.gossip');
        self::assertEquals($gossip, $viaValinor);

        // The hand-rolled codec decodes Valinor's bytes.
        $viaCodec = $this->codec->unpackGossip($valinor->serialize($gossip));
        self::assertEquals($gossip, $viaCodec);
    }

    #[Test]
    public function leaveWireFormatInteropsWithTheValinorSerializerBothWays(): void
    {
        // Rolling-upgrade guarantee: the hand-rolled codec and the previous Valinor-backed serializer
        // produce mutually-decodable msgpack, so a node on either encoder interops during an upgrade.
        $registry = new TypeRegistry();
        $registry->registerFromAttribute(LeavePayload::class);
        $valinor = new MessagePackMessageSerializer($registry);

        $leave = new LeavePayload('/cluster/prod/dc1/nexus/node-1');

        // Valinor decodes the hand-rolled bytes.
        $viaValinor = $valinor->deserialize($this->codec->packLeave($leave), 'cluster.leave');
        self::assertEquals($leave, $viaValinor);

        // The hand-rolled codec decodes Valinor's bytes.
        $viaCodec = $this->codec->unpackLeave($valinor->serialize($leave));
        self::assertEquals($leave, $viaCodec);
    }

    protected function setUp(): void
    {
        $this->codec = new ControlFrameCodec();
    }

    private static function topology(): ClusterTopology
    {
        return ClusterTopology::create(
            clusterName: 'prod',
            self: new NodeAddress('prod', 'dc1', 'nexus', 'node-1'),
            bindEndpoint: NodeEndpoint::fromString('127.0.0.1:7361'),
            advertiseEndpoint: NodeEndpoint::fromString('127.0.0.1:7361'),
            seeds: [NodeEndpoint::fromString('127.0.0.1:7362')],
        );
    }
}
