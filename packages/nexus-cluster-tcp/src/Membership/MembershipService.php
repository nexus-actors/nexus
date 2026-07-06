<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Membership;

use DateTimeImmutable;
use InvalidArgumentException;
use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\ClusterTopology;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Cluster\Tcp\Payload\GossipPayload;
use Monadial\Nexus\Cluster\Tcp\Payload\HandshakeAck;
use Monadial\Nexus\Runtime\Duration;
use Psr\Clock\ClockInterface;

use function count;
use function explode;
use function min;

/**
 * @psalm-api
 *
 * Plain (non-actor) state machine that folds link events, handshakes, gossip,
 * and Leave notices into an immutable ClusterView, and drives phi-accrual +
 * connection-death failure detection. The owning actor (C1.6) supplies wall
 * time via the injected clock and pumps `tick()` on the gossip/heartbeat timer;
 * this class stays pure and deterministic under a TestClock.
 *
 * Transitions:
 *   - handshake accept / gossip merge / any inbound frame → member Up (or recover).
 *   - phi > threshold → Up → Suspect (reason Phi).
 *   - unexpected link close → Up → Suspect (reason Connection); an intentional
 *     local close is ignored so we do not suspect a peer we chose to disconnect.
 *   - Suspect longer than the give-up window → Down (removed from the view).
 *   - Leave → Down (removed immediately).
 *
 * View changes are broadcast to `onViewChange` listeners as `MembershipEvent`s.
 */
final class MembershipService
{
    private const int PROTOCOL_VERSION = 1;

    private ClusterView $view;

    private readonly string $selfKey;

    private readonly Duration $downAfter;

    private int $selfIncarnation = 1;

    /** @var array<string, DateTimeImmutable> */
    private array $suspectSince = [];

    /** @var list<callable(ClusterView, list<MembershipEvent>): void> */
    private array $listeners = [];

    public function __construct(
        private readonly ClusterTopology $topology,
        private readonly ClockInterface $clock,
        private readonly PhiAccrualDetector $detector,
        private readonly PeerSelector $peerSelector,
        ?Duration $downAfter = null,
    ) {
        $this->selfKey = $topology->self->toPathPrefix();
        $this->downAfter = $downAfter ?? Duration::seconds(10);
        $this->view = ClusterView::empty()->withMember(new MemberRecord(
            $topology->self,
            $topology->advertiseEndpoint,
            $this->selfIncarnation,
            MemberStatus::Up,
            $this->clock->now(),
        ));
    }

    public function currentView(): ClusterView
    {
        return $this->view;
    }

    /**
     * @param callable(ClusterView, list<MembershipEvent>): void $listener
     */
    public function onViewChange(callable $listener): void
    {
        $this->listeners[] = $listener;
    }

    public function onHandshake(
        NodeAddress $peer,
        NodeEndpoint $endpoint,
        string $clusterName,
        int $protocolVersion,
        ClusterView $theirView,
    ): HandshakeAck {
        if ($clusterName !== $this->topology->clusterName) {
            return new HandshakeAck(false, 'Cluster name mismatch.', []);
        }

        if ($protocolVersion !== self::PROTOCOL_VERSION) {
            return new HandshakeAck(false, 'Protocol version mismatch.', []);
        }

        $now = $this->clock->now();
        $events = $this->recordLiveness($peer, $endpoint, $now);

        foreach ($this->mergeView($theirView) as $event) {
            $events[] = $event;
        }

        $this->notify($events);

        return new HandshakeAck(true, null, $this->viewToMap());
    }

    public function onGossip(NodeAddress $peer, GossipPayload $payload): void
    {
        $this->notify($this->mergeView($this->viewFromGossip($payload, $this->clock->now())));
    }

    public function onPing(NodeAddress $peer): void
    {
        $this->notify($this->recordLiveness($peer, null, $this->clock->now()));
    }

    public function onPong(NodeAddress $peer): void
    {
        $this->notify($this->recordLiveness($peer, null, $this->clock->now()));
    }

    public function onFrameFromPeer(NodeAddress $peer, NodeEndpoint $endpoint): void
    {
        $this->notify($this->recordLiveness($peer, $endpoint, $this->clock->now()));
    }

    public function onLinkClosed(NodeAddress $peer, bool $intentional): void
    {
        if ($intentional) {
            return;
        }

        $key = $peer->toPathPrefix();

        if ($key === $this->selfKey || !$this->view->has($peer)) {
            return;
        }

        if ($this->view->members[$key]->status !== MemberStatus::Up) {
            return;
        }

        $now = $this->clock->now();
        $this->view = $this->view->withStatus($peer, MemberStatus::Suspect, $now);
        $this->suspectSince[$key] = $now;

        $this->notify([new NodeSuspected($peer, SuspicionReason::Connection)]);
    }

    public function onLeave(NodeAddress $peer): void
    {
        $key = $peer->toPathPrefix();

        if ($key === $this->selfKey || !$this->view->has($peer)) {
            return;
        }

        $this->view = $this->view->withoutNode($peer);
        unset($this->suspectSince[$key]);

        $this->notify([new NodeDown($peer)]);
    }

    /**
     * Bump the local incarnation on rejoin so peers holding a stale record for
     * this node accept the fresher entry during gossip merge.
     */
    public function rejoin(): void
    {
        ++$this->selfIncarnation;
        $this->view = $this->view->withMember(new MemberRecord(
            $this->topology->self,
            $this->topology->advertiseEndpoint,
            $this->selfIncarnation,
            MemberStatus::Up,
            $this->clock->now(),
        ));
    }

    /**
     * Run failure detection and produce this round's gossip payloads (one per
     * randomly selected Up peer, capped at three).
     *
     * @return list<GossipPayload>
     */
    public function tick(DateTimeImmutable $now): array
    {
        $events = [];

        foreach ($this->view->nodes() as $record) {
            $key = $record->address->toPathPrefix();

            if ($key === $this->selfKey) {
                continue;
            }

            if (
                $record->status === MemberStatus::Up
                && $this->detector->phi($key, $now) > $this->topology->phiThreshold
            ) {
                $this->view = $this->view->withStatus($record->address, MemberStatus::Suspect, $now);
                $this->suspectSince[$key] = $now;
                $events[] = new NodeSuspected($record->address, SuspicionReason::Phi);

                continue;
            }

            if ($record->status === MemberStatus::Suspect) {
                $since = $this->suspectSince[$key] ?? $now;
                $this->suspectSince[$key] = $since;

                if (self::elapsedMillis($now, $since) >= (float) $this->downAfter->toMillis()) {
                    $this->view = $this->view->withoutNode($record->address);
                    unset($this->suspectSince[$key]);
                    $events[] = new NodeDown($record->address);
                }
            }
        }

        $this->notify($events);

        return $this->buildGossip();
    }

    /**
     * @return list<GossipPayload>
     */
    private function buildGossip(): array
    {
        $candidates = [];

        foreach ($this->view->upNodes() as $record) {
            $key = $record->address->toPathPrefix();

            if ($key !== $this->selfKey) {
                $candidates[] = $key;
            }
        }

        if (count($candidates) === 0) {
            return [];
        }

        $selected = $this->peerSelector->select($candidates, min(3, count($candidates)));
        $map = $this->viewToMap();

        $payloads = [];

        foreach ($selected as $_peer) {
            $payloads[] = new GossipPayload($map, []);
        }

        return $payloads;
    }

    /**
     * @return list<MembershipEvent>
     */
    private function recordLiveness(NodeAddress $peer, ?NodeEndpoint $endpoint, DateTimeImmutable $now): array
    {
        $key = $peer->toPathPrefix();
        $this->detector->heartbeat($key, $now);

        if ($key === $this->selfKey) {
            return [];
        }

        if ($this->view->has($peer)) {
            $record = $this->view->members[$key];
            $recovered = $record->status !== MemberStatus::Up;

            $this->view = $this->view->withStatus($peer, MemberStatus::Up, $now);

            if (!$recovered) {
                return [];
            }

            unset($this->suspectSince[$key]);

            return [new NodeUp($record->address, $record->endpoint)];
        }

        if ($endpoint === null) {
            return [];
        }

        $this->view = $this->view->withMember(new MemberRecord($peer, $endpoint, 1, MemberStatus::Up, $now));

        return [new NodeUp($peer, $endpoint)];
    }

    /**
     * Merge an incoming view and emit NodeUp for members newly learned as Up.
     *
     * @return list<MembershipEvent>
     */
    private function mergeView(ClusterView $incoming): array
    {
        $before = $this->view;
        $this->view = $this->view->merge($incoming);

        $events = [];

        foreach ($incoming->members as $key => $record) {
            if ($key === $this->selfKey || $before->has($record->address)) {
                continue;
            }

            $merged = $this->view->members[$key];

            if ($merged->status === MemberStatus::Up) {
                $events[] = new NodeUp($merged->address, $merged->endpoint);
            }
        }

        return $events;
    }

    private function viewFromGossip(GossipPayload $payload, DateTimeImmutable $now): ClusterView
    {
        $view = ClusterView::empty();

        foreach ($payload->view as $prefix => $hostPort) {
            if ($prefix === $this->selfKey) {
                continue;
            }

            $address = self::nodeAddressFromPathPrefix($prefix);

            if ($address === null) {
                continue;
            }

            try {
                $endpoint = NodeEndpoint::fromString($hostPort);
            } catch (InvalidArgumentException) {
                continue;
            }

            $view = $view->withMember(new MemberRecord($address, $endpoint, 1, MemberStatus::Up, $now));
        }

        return $view;
    }

    /**
     * @return array<string, string>
     */
    private function viewToMap(): array
    {
        $map = [];

        foreach ($this->view->members as $key => $record) {
            $map[$key] = (string) $record->endpoint;
        }

        return $map;
    }

    /**
     * @param list<MembershipEvent> $events
     */
    private function notify(array $events): void
    {
        if (count($events) === 0) {
            return;
        }

        foreach ($this->listeners as $listener) {
            $listener($this->view, $events);
        }
    }

    private static function nodeAddressFromPathPrefix(string $prefix): ?NodeAddress
    {
        $segments = explode('/', $prefix);

        if (count($segments) !== 6 || $segments[1] !== 'cluster') {
            return null;
        }

        return new NodeAddress($segments[2], $segments[3], $segments[4], $segments[5]);
    }

    private static function elapsedMillis(DateTimeImmutable $now, DateTimeImmutable $since): float
    {
        return ((float) $now->format('U.u') - (float) $since->format('U.u')) * 1000.0;
    }
}
