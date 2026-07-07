<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Membership;

use Closure;
use Monadial\Nexus\Cluster\Tcp\ClusterTopology;
use Monadial\Nexus\Cluster\Tcp\Frame;
use Monadial\Nexus\Cluster\Tcp\FrameType;
use Monadial\Nexus\Cluster\Tcp\Payload\Handshake;
use Monadial\Nexus\Cluster\Tcp\Payload\HandshakeAck;
use Monadial\Nexus\Serialization\MessageSerializer;
use Override;

/**
 * @psalm-api
 *
 * Executes the outbound effects produced by membership transitions over TCP.
 *
 * For HandshakeResponse: sends its own Handshake frame first (so the peer can
 * identify who is replying), then the HandshakeAck. The Handshake is sent only
 * once per prefix — subsequent HandshakeResponse effects reuse the established
 * identity. This keeps the handshake exchange from looping when two nodes both
 * respond to each other's introductions.
 *
 * For SendGossip: serialises the GossipPayload once and sends to each Up peer
 * via the shared sender closure. Targets that have no registered endpoint are
 * silently skipped (the membership layer will retry on the next gossip tick).
 *
 * The $sender closure routes frames through ClusterNode's shared connection
 * infrastructure: accepted inbound links are preferred; a new outbound
 * PeerConnection is created lazily when no accepted link exists.
 */
final class TcpMembershipEffectInterpreter implements MembershipEffectInterpreter
{
    /** @var array<string, true> path-prefixes to which we have already sent our Handshake */
    private array $handshakeSentTo = [];

    /**
     * @param Closure(string $prefix, Frame $frame): void $sender
     *        Routes a frame to the peer identified by NodeAddress path-prefix.
     *        Injected by ClusterNode::boot to share the connection infrastructure.
     */
    public function __construct(
        private readonly ClusterTopology $topology,
        private readonly MessageSerializer $frameSerializer,
        private readonly Closure $sender,
    ) {}

    #[Override]
    public function interpret(MembershipEffect $effect): void
    {
        if ($effect instanceof HandshakeResponse) {
            $this->sendHandshakeAck($effect);

            return;
        }

        if ($effect instanceof SendGossip) {
            $this->sendGossip($effect);
        }
    }

    private function sendHandshakeAck(HandshakeResponse $effect): void
    {
        $prefix = $effect->peer->toPathPrefix();

        // Send our own Handshake first so the peer learns our identity before
        // processing any subsequent gossip or message frames from us.
        if (!isset($this->handshakeSentTo[$prefix])) {
            $this->handshakeSentTo[$prefix] = true;
            $handshakeBytes = $this->frameSerializer->serialize($this->buildSelfHandshake());
            ($this->sender)($prefix, new Frame(FrameType::Handshake, $handshakeBytes));
        }

        $ack = new HandshakeAck($effect->accepted, $effect->reason, $effect->view);
        $ackBytes = $this->frameSerializer->serialize($ack);
        ($this->sender)($prefix, new Frame(FrameType::HandshakeAck, $ackBytes));
    }

    private function sendGossip(SendGossip $effect): void
    {
        $gossipBytes = $this->frameSerializer->serialize($effect->payload);
        $gossipFrame = new Frame(FrameType::Gossip, $gossipBytes);

        foreach ($effect->targets as $prefix) {
            ($this->sender)($prefix, $gossipFrame);
        }
    }

    private function buildSelfHandshake(): Handshake
    {
        $self = $this->topology->self;

        return new Handshake(
            clusterName: $this->topology->clusterName,
            node: [
                'application' => $self->application,
                'cluster' => $self->cluster,
                'datacenter' => $self->datacenter,
                'node' => $self->node,
            ],
            advertise: (string) $this->topology->advertiseEndpoint,
        );
    }
}
