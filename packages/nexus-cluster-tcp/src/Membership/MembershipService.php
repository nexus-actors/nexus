<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Membership;

use DateTimeImmutable;
use InvalidArgumentException;
use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\ClusterTopology;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Cluster\Tcp\Payload\GossipPayload;
use Monadial\Nexus\Runtime\Duration;

use function count;
use function explode;
use function max;
use function min;

/**
 * @psalm-api
 *
 * Pure membership transition functions. Each method takes the current membership
 * state (ClusterView, suspectSince timestamps, self incarnation) plus an event
 * input, applies the appropriate state-machine logic, and returns a
 * MembershipTransition describing the new state, emitted events, and outbound
 * effects. No I/O is performed; the owning actor (C1.6d) executes the effects.
 *
 * The PhiAccrualDetector is mutable and owned by the actor; it is passed in to
 * liveness/tick transitions so that heartbeat timestamps are recorded (the
 * detector's phi calculation requires every arrival to be fed in).
 *
 * State the actor must track across transitions:
 *   - ClusterView                      — returned as newView in every transition.
 *   - array<string,DateTimeImmutable>  — returned as newSuspectSince; records when
 *                                        each peer entered Suspect so applyTick can
 *                                        evaluate the give-up window.
 *   - int $selfIncarnation             — returned as newSelfIncarnation; bumped by
 *                                        applyRejoin.
 *
 * Transitions:
 *   - applyHandshake  — validate peer, record liveness, merge view → HandshakeResponse effect.
 *   - applyGossip     — decode enriched GossipPayload, merge view.
 *   - applyLiveness   — record any inbound frame (frame / ping / pong), feed detector.
 *   - applyLinkClosed — suspect peer on unexpected close; no-op on intentional close.
 *   - applyLeave      — remove peer immediately.
 *   - applyRejoin     — bump local incarnation.
 *   - applyTick       — phi failure detection + give-up window + SendGossip effect.
 */
final readonly class MembershipService
{
    /** Wire protocol version asserted in the handshake; the single source of truth for the mesh. */
    public const int PROTOCOL_VERSION = 1;

    private string $selfKey;

    private Duration $downAfter;

    public function __construct(private ClusterTopology $topology, ?Duration $downAfter = null)
    {
        $this->selfKey = $topology->self->toPathPrefix();
        $this->downAfter = $downAfter ?? Duration::seconds(10);
    }

    /**
     * Build the initial MembershipTransition: self is Up at incarnation 1, no
     * suspicions, no events, no effects. The actor uses this as its starting state.
     */
    public function initialState(DateTimeImmutable $now): MembershipTransition
    {
        $view = ClusterView::empty()->withMember(new MemberRecord(
            $this->topology->self,
            $this->topology->advertiseEndpoint,
            1,
            MemberStatus::Up,
            $now,
        ));

        return new MembershipTransition($view, [], [], [], 1);
    }

    /**
     * Apply an inbound handshake from a peer. Validates cluster name and protocol
     * version; if invalid returns a rejection HandshakeResponse and leaves state
     * unchanged. On success, records liveness, merges the peer's view, and
     * returns an accepted HandshakeResponse containing the local post-merge view.
     *
     * @param array<string, DateTimeImmutable> $suspectSince
     * @param DateTimeImmutable $observedAt Socket-ingress timestamp fed to the phi detector; keeps
     *        processing-queue latency out of the failure-detector window. Everything else uses $now.
     */
    public function applyHandshake(
        ClusterView $view,
        array $suspectSince,
        int $selfIncarnation,
        PhiAccrualDetector $detector,
        NodeAddress $peer,
        NodeEndpoint $endpoint,
        string $clusterName,
        int $protocolVersion,
        ClusterView $theirView,
        DateTimeImmutable $observedAt,
        DateTimeImmutable $now,
    ): MembershipTransition {
        if ($clusterName !== $this->topology->clusterName) {
            return new MembershipTransition(
                $view,
                [],
                [new HandshakeResponse($peer, false, 'Cluster name mismatch.', [])],
                $suspectSince,
                $selfIncarnation,
            );
        }

        if ($protocolVersion !== self::PROTOCOL_VERSION) {
            return new MembershipTransition(
                $view,
                [],
                [new HandshakeResponse($peer, false, 'Protocol version mismatch.', [])],
                $suspectSince,
                $selfIncarnation,
            );
        }

        [$view1, $suspectSince1, $events1] = $this->recordLiveness(
            $view,
            $suspectSince,
            $detector,
            $peer,
            $endpoint,
            $observedAt,
            $now,
        );
        [$view2, $suspectSince2, $events2] = $this->mergeView($view1, $suspectSince1, $theirView);

        return new MembershipTransition(
            $view2,
            [...$events1, ...$events2],
            [new HandshakeResponse($peer, true, null, $this->viewToMap($view2))],
            $suspectSince2,
            $selfIncarnation,
        );
    }

    /**
     * Apply an inbound GossipPayload from a peer. Decodes the enriched member
     * list (including incarnation and status) and merges it into the current view.
     * Unlike applyLiveness, gossip does not feed the phi detector.
     *
     * Incarnation refutation (SWIM/Akka-style): if the gossip asserts THIS node as Suspect or
     * Down, we bump our incarnation above the value the peer holds and re-assert ourselves as Up.
     * Because {@see ClusterView::merge()} lets a higher incarnation win deterministically, our next
     * gossip overrides the stale suspicion on every peer — including peers with no direct link to
     * us, which could otherwise never recover us via liveness. Self is excluded from mergeView, so
     * our own record is only ever changed here (or at startup).
     *
     * @param array<string, DateTimeImmutable> $suspectSince
     * @psalm-suppress UnusedParam $peer retained for API symmetry; gossip-source validation is a future extension.
     */
    public function applyGossip(
        ClusterView $view,
        array $suspectSince,
        int $selfIncarnation,
        NodeAddress $peer,
        GossipPayload $payload,
        DateTimeImmutable $now,
    ): MembershipTransition {
        $incoming = $this->gossipToView($payload, $now);
        [$newView, $newSuspectSince, $events] = $this->mergeView($view, $suspectSince, $incoming);

        $suspectedAt = $this->peerAssertedSelfSuspicion($payload);

        if ($suspectedAt !== null) {
            // Reuse applyRejoin's "insert self Up at a higher incarnation" primitive, flooring the
            // bump above the incarnation the peer holds so the refutation always wins the merge.
            // Clamp at PHP_INT_MAX: a peer asserting PHP_INT_MAX (forged/maxed) would otherwise make
            // applyRejoin's +1 overflow to float, corrupting the int-typed incarnation contract. We
            // stay pinned at PHP_INT_MAX instead — still the maximal, merge-winning value.
            $floor = max($selfIncarnation, $suspectedAt);
            $baseForRejoin = $floor >= PHP_INT_MAX
                ? PHP_INT_MAX - 1
                : $floor;
            $rejoin = $this->applyRejoin($newView, $newSuspectSince, $baseForRejoin, $now);

            return new MembershipTransition(
                $rejoin->newView,
                $events,
                [],
                $newSuspectSince,
                $rejoin->newSelfIncarnation,
            );
        }

        return new MembershipTransition($newView, $events, [], $newSuspectSince, $selfIncarnation);
    }

    /**
     * Record liveness for a peer from any inbound frame (frame, ping, or pong).
     * Feeds the phi detector, adds the peer to the view if new (requires a
     * non-null endpoint), or recovers the peer from Suspect to Up if already known.
     *
     * @param array<string, DateTimeImmutable> $suspectSince
     */
    public function applyLiveness(
        ClusterView $view,
        array $suspectSince,
        int $selfIncarnation,
        PhiAccrualDetector $detector,
        NodeAddress $peer,
        ?NodeEndpoint $endpoint,
        DateTimeImmutable $observedAt,
        DateTimeImmutable $now,
    ): MembershipTransition {
        [$newView, $newSuspectSince, $events] = $this->recordLiveness(
            $view,
            $suspectSince,
            $detector,
            $peer,
            $endpoint,
            $observedAt,
            $now,
        );

        return new MembershipTransition($newView, $events, [], $newSuspectSince, $selfIncarnation);
    }

    /**
     * Handle a TCP link closure. An intentional close (local initiative) is a
     * no-op — we do not suspect a peer we chose to disconnect. An unexpected close
     * moves an Up peer to Suspect with reason Connection.
     *
     * @param array<string, DateTimeImmutable> $suspectSince
     */
    public function applyLinkClosed(
        ClusterView $view,
        array $suspectSince,
        int $selfIncarnation,
        NodeAddress $peer,
        bool $intentional,
        DateTimeImmutable $now,
    ): MembershipTransition {
        if ($intentional) {
            return new MembershipTransition($view, [], [], $suspectSince, $selfIncarnation);
        }

        $key = $peer->toPathPrefix();

        if ($key === $this->selfKey || !$view->has($peer)) {
            return new MembershipTransition($view, [], [], $suspectSince, $selfIncarnation);
        }

        if ($view->members[$key]->status !== MemberStatus::Up) {
            return new MembershipTransition($view, [], [], $suspectSince, $selfIncarnation);
        }

        $newSuspectSince = $suspectSince;
        $newSuspectSince[$key] = $now;

        return new MembershipTransition(
            $view->withStatus($peer, MemberStatus::Suspect, $now),
            [new NodeSuspected($peer, SuspicionReason::Connection)],
            [],
            $newSuspectSince,
            $selfIncarnation,
        );
    }

    /**
     * Handle a Leave notice from a peer. Removes the peer from the view
     * immediately and emits NodeDown regardless of its current status.
     *
     * @param array<string, DateTimeImmutable> $suspectSince
     */
    public function applyLeave(
        ClusterView $view,
        array $suspectSince,
        int $selfIncarnation,
        NodeAddress $peer,
    ): MembershipTransition {
        $key = $peer->toPathPrefix();

        if ($key === $this->selfKey || !$view->has($peer)) {
            return new MembershipTransition($view, [], [], $suspectSince, $selfIncarnation);
        }

        $newSuspectSince = $suspectSince;
        unset($newSuspectSince[$key]);

        return new MembershipTransition(
            $view->withoutNode($peer),
            [new NodeDown($peer)],
            [],
            $newSuspectSince,
            $selfIncarnation,
        );
    }

    /**
     * Bump the local incarnation number on rejoin. Inserts a fresh self-record
     * with the higher incarnation so peers holding a stale record will accept the
     * newer entry during gossip merge.
     *
     * @param array<string, DateTimeImmutable> $suspectSince
     */
    public function applyRejoin(
        ClusterView $view,
        array $suspectSince,
        int $selfIncarnation,
        DateTimeImmutable $now,
    ): MembershipTransition {
        $newIncarnation = $selfIncarnation + 1;
        $newView = $view->withMember(new MemberRecord(
            $this->topology->self,
            $this->topology->advertiseEndpoint,
            $newIncarnation,
            MemberStatus::Up,
            $now,
        ));

        return new MembershipTransition($newView, [], [], $suspectSince, $newIncarnation);
    }

    /**
     * Run one failure-detection and gossip tick. Iterates all non-self members:
     * moves Up peers whose phi exceeds the threshold to Suspect (reason: Phi),
     * and removes Suspect peers past the give-up window with NodeDown. Returns a
     * SendGossip effect for up to three randomly-selected Up peers.
     *
     * @param array<string, DateTimeImmutable> $suspectSince
     */
    public function applyTick(
        ClusterView $view,
        array $suspectSince,
        int $selfIncarnation,
        PhiAccrualDetector $detector,
        PeerSelector $peerSelector,
        DateTimeImmutable $now,
    ): MembershipTransition {
        $events = [];
        $newView = $view;
        $newSuspectSince = $suspectSince;

        // Minimum-members floor: below it, do NOT declare peers Down. This stops a minority side of
        // a partition from independently evicting the majority and running as a split-brain
        // singleton. Suspicion still happens (local observation); only the destructive removal is
        // gated. 0 disables the floor (default). See ClusterTopology::withMinimumMembers().
        $reachable = count($view->upNodes());
        $belowQuorum = $this->topology->minimumMembers > 0 && $reachable < $this->topology->minimumMembers;
        $suppressedDown = false;

        foreach ($view->nodes() as $record) {
            $key = $record->address->toPathPrefix();

            if ($key === $this->selfKey) {
                continue;
            }

            if ($record->status === MemberStatus::Up) {
                $phiExceeded = $detector->phi($key, $now) > $this->topology->phiThreshold;
                // Absolute-silence fallback: a peer with an empty phi window (handshaked once, then
                // silent) never crosses the phi threshold, so phi alone would never suspect it.
                // Suspect it once we have heard nothing for the no-heartbeat window regardless of phi.
                $silentMs = $detector->millisSinceLastHeartbeat($key, $now);
                $silentTooLong = $silentMs !== null && $silentMs > (float) $this->downAfter->toMillis();

                if ($phiExceeded || $silentTooLong) {
                    $newView = $newView->withStatus($record->address, MemberStatus::Suspect, $now);
                    $newSuspectSince[$key] = $now;
                    $events[] = new NodeSuspected(
                        $record->address,
                        $phiExceeded
                            ? SuspicionReason::Phi
                            : SuspicionReason::Silence,
                    );

                    continue;
                }
            }

            if ($record->status === MemberStatus::Suspect) {
                $since = $newSuspectSince[$key] ?? $now;
                $newSuspectSince[$key] = $since;

                if (self::elapsedMillis($now, $since) >= (float) $this->downAfter->toMillis()) {
                    if ($belowQuorum) {
                        // Hold the Suspect; the floor forbids evicting it while we lack quorum.
                        $suppressedDown = true;
                    } else {
                        $newView = $newView->withoutNode($record->address);
                        unset($newSuspectSince[$key]);
                        // NodeDown → MembershipActor::apply() clears the detector window (one place
                        // every Down path funnels through), so no per-site forget() is needed here.
                        $events[] = new NodeDown($record->address);
                    }
                }
            }
        }

        if ($suppressedDown) {
            $events[] = new ClusterDegraded($reachable, $this->topology->minimumMembers);
        }

        $effects = $this->buildGossipEffects($newView, $peerSelector);

        return new MembershipTransition($newView, $events, $effects, $newSuspectSince, $selfIncarnation);
    }

    /**
     * Record a liveness event for a peer: feed the phi detector and update the
     * view (add new member, or recover from Suspect to Up). Returns the updated
     * view, suspectSince map, and any membership events.
     *
     * @param array<string, DateTimeImmutable> $suspectSince
     *
     * @return array{ClusterView, array<string, DateTimeImmutable>, list<MembershipEvent>}
     */
    private function recordLiveness(
        ClusterView $view,
        array $suspectSince,
        PhiAccrualDetector $detector,
        NodeAddress $peer,
        ?NodeEndpoint $endpoint,
        DateTimeImmutable $observedAt,
        DateTimeImmutable $now,
    ): array {
        $key = $peer->toPathPrefix();
        $detector->heartbeat($key, $observedAt);

        if ($key === $this->selfKey) {
            return [$view, $suspectSince, []];
        }

        if ($view->has($peer)) {
            $record = $view->members[$key];
            $recovered = $record->status !== MemberStatus::Up;
            $newView = $view->withStatus($peer, MemberStatus::Up, $now);

            if (!$recovered) {
                return [$newView, $suspectSince, []];
            }

            $newSuspectSince = $suspectSince;
            unset($newSuspectSince[$key]);

            return [$newView, $newSuspectSince, [new NodeUp($record->address, $record->endpoint)]];
        }

        if ($endpoint === null) {
            return [$view, $suspectSince, []];
        }

        $newView = $view->withMember(new MemberRecord($peer, $endpoint, 1, MemberStatus::Up, $now));

        return [$newView, $suspectSince, [new NodeUp($peer, $endpoint)]];
    }

    /**
     * Merge an incoming view into the current view. Emits NodeUp for newly-learned
     * Up members, and NodeUp/NodeSuspected/NodeDown for status changes on known
     * members. Updates suspectSince accordingly.
     *
     * @param array<string, DateTimeImmutable> $suspectSince
     *
     * @return array{ClusterView, array<string, DateTimeImmutable>, list<MembershipEvent>}
     */
    private function mergeView(ClusterView $view, array $suspectSince, ClusterView $incoming): array
    {
        $before = $view;
        $merged = $view->merge($incoming);
        $events = [];
        $newSuspectSince = $suspectSince;

        foreach ($incoming->members as $key => $record) {
            if ($key === $this->selfKey || $before->has($record->address)) {
                continue;
            }

            $mergedRecord = $merged->members[$key];

            $events[] = match ($mergedRecord->status) {
                MemberStatus::Up => new NodeUp($mergedRecord->address, $mergedRecord->endpoint),
                MemberStatus::Suspect => new NodeSuspected($mergedRecord->address, SuspicionReason::Gossip),
                MemberStatus::Down => new NodeDown($mergedRecord->address),
            };

            if ($mergedRecord->status === MemberStatus::Suspect) {
                $newSuspectSince[$key] ??= $mergedRecord->lastSeen;
            } elseif ($mergedRecord->status === MemberStatus::Down) {
                $merged = $merged->withoutNode($mergedRecord->address);
                unset($newSuspectSince[$key]);
            }
        }

        foreach ($before->members as $key => $beforeRecord) {
            if ($key === $this->selfKey || !isset($merged->members[$key])) {
                continue;
            }

            $afterRecord = $merged->members[$key];

            if ($beforeRecord->status === $afterRecord->status) {
                continue;
            }

            $events[] = match ($afterRecord->status) {
                MemberStatus::Up => new NodeUp($afterRecord->address, $afterRecord->endpoint),
                MemberStatus::Suspect => new NodeSuspected($afterRecord->address, SuspicionReason::Gossip),
                MemberStatus::Down => new NodeDown($afterRecord->address),
            };

            if ($afterRecord->status === MemberStatus::Up) {
                unset($newSuspectSince[$key]);
            } elseif ($afterRecord->status === MemberStatus::Suspect) {
                $newSuspectSince[$key] ??= $afterRecord->lastSeen;
            } else {
                $merged = $merged->withoutNode($afterRecord->address);
                unset($newSuspectSince[$key]);
            }
        }

        return [$merged, $newSuspectSince, $events];
    }

    /**
     * Convert a GossipPayload to a ClusterView using local `$now` as lastSeen for
     * all received members (the sender's timestamps are irrelevant to merge
     * tie-breaking, which uses incarnation and status rank as primary criteria).
     * Self is excluded from the resulting view.
     */
    private function gossipToView(GossipPayload $payload, DateTimeImmutable $now): ClusterView
    {
        $view = ClusterView::empty();

        foreach ($payload->members as $member) {
            $addressKey = $member['address'];

            if ($addressKey === $this->selfKey) {
                continue;
            }

            $address = self::nodeAddressFromPathPrefix($addressKey);

            if ($address === null) {
                continue;
            }

            try {
                $endpoint = NodeEndpoint::fromString($member['endpoint']);
            } catch (InvalidArgumentException) {
                continue;
            }

            $status = self::statusFromInt($member['status']);

            if ($status === null) {
                continue;
            }

            $view = $view->withMember(new MemberRecord(
                $address,
                $endpoint,
                $member['incarnation'],
                $status,
                $now,
            ));
        }

        return $view;
    }

    /**
     * Scan the raw gossip members for an assertion that THIS node is non-Up (Suspect or Down),
     * returning the incarnation at which the peer holds us, or null if there is no such assertion.
     * Operates on the raw payload because {@see gossipToView()} deliberately excludes self.
     */
    private function peerAssertedSelfSuspicion(GossipPayload $payload): ?int
    {
        foreach ($payload->members as $member) {
            if ($member['address'] !== $this->selfKey) {
                continue;
            }

            $status = self::statusFromInt($member['status']);

            if ($status !== null && $status !== MemberStatus::Up) {
                return $member['incarnation'];
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function viewToMap(ClusterView $view): array
    {
        $map = [];

        foreach ($view->members as $key => $record) {
            $map[$key] = (string) $record->endpoint;
        }

        return $map;
    }

    /**
     * @return list<array{address: string, endpoint: string, incarnation: int, status: int}>
     */
    private function viewToGossipMembers(ClusterView $view): array
    {
        $members = [];

        foreach ($view->nodes() as $record) {
            $members[] = [
                'address' => $record->address->toPathPrefix(),
                'endpoint' => (string) $record->endpoint,
                'incarnation' => $record->incarnation,
                'status' => $record->status->rank(),
            ];
        }

        return $members;
    }

    /**
     * @return list<MembershipEffect>
     */
    private function buildGossipEffects(ClusterView $view, PeerSelector $peerSelector): array
    {
        $candidates = [];

        // Gossip targets include SUSPECT members, not just Up ones. A node that every
        // peer has marked Suspect would otherwise receive no gossip at all — it could
        // never see itself asserted Suspect, never bump its incarnation, and the
        // refutation that ends a stale-suspicion epidemic would be unreachable
        // (observed as persistent Suspect/Up flapping in a 16-node mesh). Its TCP
        // links are still alive; gossiping to it costs one frame and delivers the
        // refutation trigger. Down members are already out of the view entirely.
        foreach ($view->nodes() as $record) {
            $key = $record->address->toPathPrefix();

            if ($key !== $this->selfKey) {
                $candidates[] = $key;
            }
        }

        if (count($candidates) === 0) {
            return [];
        }

        $selected = $peerSelector->select($candidates, min(3, count($candidates)));

        if ($selected === []) {
            return [];
        }

        return [new SendGossip($selected, new GossipPayload($this->viewToGossipMembers($view), []))];
    }

    private static function nodeAddressFromPathPrefix(string $prefix): ?NodeAddress
    {
        $segments = explode('/', $prefix);

        if (count($segments) !== 6 || $segments[1] !== 'cluster') {
            return null;
        }

        try {
            return new NodeAddress($segments[2], $segments[3], $segments[4], $segments[5]);
        } catch (InvalidArgumentException) {
            // Gossip carried an identity with non-URL-safe segments — treat as malformed, skip it.
            return null;
        }
    }

    private static function elapsedMillis(DateTimeImmutable $now, DateTimeImmutable $since): float
    {
        return ((float) $now->format('U.u') - (float) $since->format('U.u')) * 1000.0;
    }

    /**
     * Convert a gossip status integer back to MemberStatus.
     * Integers correspond to MemberStatus::rank(): Up = 1, Suspect = 2, Down = 3.
     */
    private static function statusFromInt(int $statusInt): ?MemberStatus
    {
        return match ($statusInt) {
            1 => MemberStatus::Up,
            2 => MemberStatus::Suspect,
            3 => MemberStatus::Down,
            default => null,
        };
    }
}
