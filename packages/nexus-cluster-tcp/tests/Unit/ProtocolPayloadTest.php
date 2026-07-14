<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Unit;

use Monadial\Nexus\Cluster\Tcp\Payload\GossipPayload;
use Monadial\Nexus\Cluster\Tcp\Payload\Handshake;
use Monadial\Nexus\Cluster\Tcp\Payload\HandshakeAck;
use Monadial\Nexus\Cluster\Tcp\Payload\LeavePayload;
use Monadial\Nexus\Cluster\Tcp\Payload\MessagePayload;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GossipPayload::class)]
#[CoversClass(Handshake::class)]
#[CoversClass(HandshakeAck::class)]
#[CoversClass(LeavePayload::class)]
#[CoversClass(MessagePayload::class)]
final class ProtocolPayloadTest extends TestCase
{
    #[Test]
    public function handshakeHoldsExpectedFields(): void
    {
        $hs = new Handshake(
            clusterName: 'prod',
            node: [
                'application' => 'payments',
                'cluster' => 'prod',
                'datacenter' => 'eu',
                'node' => 'node-1',
            ],
            advertise: '10.0.0.1:7355',
        );

        self::assertSame('prod', $hs->clusterName);
        self::assertSame(1, $hs->protocolVersion);
        self::assertSame('10.0.0.1:7355', $hs->advertise);
        self::assertSame('node-1', $hs->node['node']);
    }

    #[Test]
    public function handshakeProtocolVersionDefaultsToOne(): void
    {
        $hs = new Handshake(
            clusterName: 'prod',
            node: ['application' => 'svc', 'cluster' => 'prod', 'datacenter' => 'us', 'node' => 'n1'],
            advertise: 'localhost:7355',
        );

        self::assertSame(1, $hs->protocolVersion);
    }

    #[Test]
    public function handshakeAckAcceptedWithView(): void
    {
        $ack = new HandshakeAck(
            accepted: true,
            reason: null,
            view: ['prod/eu/payments/node-1' => '10.0.0.1:7355'],
        );

        self::assertTrue($ack->accepted);
        self::assertNull($ack->reason);
        self::assertSame('10.0.0.1:7355', $ack->view['prod/eu/payments/node-1']);
    }

    #[Test]
    public function handshakeAckRejectedWithReason(): void
    {
        $ack = new HandshakeAck(
            accepted: false,
            reason: 'cluster name mismatch',
            view: [],
        );

        self::assertFalse($ack->accepted);
        self::assertSame('cluster name mismatch', $ack->reason);
    }

    #[Test]
    public function messagePayloadHoldsAllFields(): void
    {
        $mp = new MessagePayload(
            targetPath: 'nexus://prod/eu/payments/node-1/user/orders',
            messageType: 'cluster.message',
            body: "\x81\xa3foo\xa3bar",
            correlationId: 'corr-123',
            replyPath: 'nexus://prod/eu/payments/node-2/reply',
            trace: ['traceparent' => '00-abc-def-01'],
        );

        self::assertSame('nexus://prod/eu/payments/node-1/user/orders', $mp->targetPath);
        self::assertSame('cluster.message', $mp->messageType);
        self::assertSame("\x81\xa3foo\xa3bar", $mp->body);
        self::assertSame('corr-123', $mp->correlationId);
        self::assertSame('nexus://prod/eu/payments/node-2/reply', $mp->replyPath);
        self::assertSame(['traceparent' => '00-abc-def-01'], $mp->trace);
    }

    #[Test]
    public function messagePayloadOptionalFieldsCanBeNull(): void
    {
        $mp = new MessagePayload(
            targetPath: 'nexus://prod/eu/svc/n1/actor',
            messageType: 'cluster.message',
            body: '',
            correlationId: null,
            replyPath: null,
            trace: [],
        );

        self::assertNull($mp->correlationId);
        self::assertNull($mp->replyPath);
        self::assertSame([], $mp->trace);
    }

    #[Test]
    public function gossipPayloadHoldsMembersAndEmptyRegistrations(): void
    {
        $gp = new GossipPayload(
            members: [
                [
                    'address' => 'prod/eu/svc/n1',
                    'endpoint' => '10.0.0.1:7355',
                    'incarnation' => 1,
                    'status' => 1,
                ],
            ],
            registrations: [],
        );

        self::assertCount(1, $gp->members);
        self::assertSame('prod/eu/svc/n1', $gp->members[0]['address']);
        self::assertSame('10.0.0.1:7355', $gp->members[0]['endpoint']);
        self::assertSame(1, $gp->members[0]['incarnation']);
        self::assertSame(1, $gp->members[0]['status']);
        self::assertSame([], $gp->registrations);
    }

    #[Test]
    public function leavePayloadHoldsNodeIdentifier(): void
    {
        $leave = new LeavePayload(node: 'prod/eu/payments/node-1');

        self::assertSame('prod/eu/payments/node-1', $leave->node);
    }
}
