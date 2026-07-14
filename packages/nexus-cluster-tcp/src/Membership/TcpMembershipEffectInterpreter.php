<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Membership;

use Closure;
use Monadial\Nexus\Cluster\Tcp\Frame;
use Monadial\Nexus\Cluster\Tcp\FrameType;
use Monadial\Nexus\Cluster\Tcp\Payload\ControlFrameCodec;
use Monadial\Nexus\Cluster\Tcp\Payload\HandshakeAck;
use Monadial\Nexus\Observability\Metric\Counter;
use Monadial\Nexus\Observability\Metric\Meter;
use Monadial\Nexus\Observability\Metric\NoopMeter;
use Override;
use Throwable;

/**
 * @psalm-api
 *
 * Executes the outbound effects produced by membership transitions over TCP.
 *
 * For HandshakeResponse: sends the HandshakeAck. The peer's identity is established by the
 * self-Handshake that {@see \Monadial\Nexus\Cluster\Tcp\ClusterNode} sends as the
 * {@see \Monadial\Nexus\Cluster\Tcp\PeerConnection} preamble on every (re)connect, so this
 * interpreter no longer sends a Handshake itself — that made identity a once-per-process fact
 * and left reconnected/restarted peers unidentifiable. Sending only the ack here keeps the
 * exchange from looping (an ack never triggers another handshake).
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
    private ?Counter $gossipRounds = null;

    /**
     * @param Closure(string $prefix, Frame $frame): void $sender
     *        Routes a frame to the peer identified by NodeAddress path-prefix.
     *        Injected by ClusterNode::boot to share the connection infrastructure.
     */
    public function __construct(
        private readonly ControlFrameCodec $controlCodec,
        private readonly Closure $sender,
        private readonly Meter $meter = new NoopMeter(),
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

        // Identity is established by the peer-facing PeerConnection handshake preamble, so this
        // effect only needs to acknowledge. Sending a Handshake here too would re-introduce the
        // once-per-process identity coupling this fix removed.
        $ack = new HandshakeAck($effect->accepted, $effect->reason, $effect->view);
        $ackBytes = $this->controlCodec->packHandshakeAck($ack);
        ($this->sender)($prefix, new Frame(FrameType::HandshakeAck, $ackBytes));
    }

    private function sendGossip(SendGossip $effect): void
    {
        $this->safely(fn(): mixed => $this->gossipRoundsCounter()->add(1));

        $gossipBytes = $this->controlCodec->packGossip($effect->payload);
        $gossipFrame = new Frame(FrameType::Gossip, $gossipBytes);

        foreach ($effect->targets as $prefix) {
            ($this->sender)($prefix, $gossipFrame);
        }
    }

    /**
     * @param callable(): mixed $fn
     */
    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable) {
            // Telemetry must never break membership effects.
        }
    }

    private function gossipRoundsCounter(): Counter
    {
        return $this->gossipRounds ??= $this->meter->counter(
            'nexus.cluster.gossip.rounds',
            '{round}',
            'Gossip rounds dispatched to peers',
        );
    }
}
