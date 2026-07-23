<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Telemetry;

use Monadial\Nexus\Observability\Metric\Counter;
use Monadial\Nexus\Observability\Metric\Meter;

/**
 * Membership/gossip instruments, created eagerly at wiring time and
 * injected as a plain instance (spec §3.5). This class is the single home of
 * the documented metric names — website/docs/packages/cluster-tcp.md.
 */
final readonly class MembershipMetrics
{
    public Counter $nodesSuspected;
    public Counter $nodesRecovered;
    public Counter $nodesPruned;
    public Counter $heartbeatsReceived;
    public Counter $gossipRounds;

    public function __construct(Meter $meter)
    {
        $this->nodesSuspected = $meter->counter('nexus.cluster.nodes.suspected', '{node}', 'Peers marked suspected');
        $this->nodesRecovered = $meter->counter(
            'nexus.cluster.nodes.recovered',
            '{node}',
            'Peers recovered from suspicion',
        );
        $this->nodesPruned = $meter->counter('nexus.cluster.nodes.pruned', '{node}', 'Peers pruned after Down');
        $this->heartbeatsReceived = $meter->counter(
            'nexus.cluster.heartbeats.received',
            '{heartbeat}',
            'Peer liveness observations',
        );
        $this->gossipRounds = $meter->counter('nexus.cluster.gossip.rounds', '{round}', 'Gossip rounds sent');
    }
}
