<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Membership;

use DateTimeImmutable;
use Monadial\Nexus\Cluster\Tcp\Membership\Message\GetClusterView;
use Monadial\Nexus\Cluster\Tcp\Membership\Message\GossipReceived;
use Monadial\Nexus\Cluster\Tcp\Membership\Message\GossipTick;
use Monadial\Nexus\Cluster\Tcp\Membership\Message\HandshakeReceived;
use Monadial\Nexus\Cluster\Tcp\Membership\Message\HeartbeatTick;
use Monadial\Nexus\Cluster\Tcp\Membership\Message\LeaveReceived;
use Monadial\Nexus\Cluster\Tcp\Membership\Message\PeerLinkClosed;
use Monadial\Nexus\Cluster\Tcp\Membership\Message\PeerLivenessObserved;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Actor\StatefulActorHandler;
use Monadial\Nexus\Core\Lifecycle\PostStop;
use Monadial\Nexus\Core\Lifecycle\PreRestart;
use Monadial\Nexus\Core\Lifecycle\Signal;
use Monadial\Nexus\Core\Supervision\SupervisionStrategy;
use Monadial\Nexus\Observability\Metric\Counter;
use Monadial\Nexus\Observability\Metric\Meter;
use Monadial\Nexus\Observability\Metric\NoopMeter;
use Monadial\Nexus\Runtime\Duration;
use Override;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * @psalm-api
 *
 * Akka-style membership actor. Owns the {@see MembershipState} (ClusterView +
 * suspectSince + selfIncarnation) and evolves it functionally by dispatching
 * each inbound message to the matching pure {@see MembershipService} transition,
 * then adopting the returned state via {@see BehaviorWithState::next()}.
 *
 * The transition returns a {@see MembershipTransition} carrying the new state,
 * the {@see MembershipEvent}s to publish, and the {@see MembershipEffect}s to
 * execute. The actor publishes every event through the injected
 * {@see MembershipEventPublisher} and hands every effect to the injected
 * {@see MembershipEffectInterpreter}; it performs no I/O itself.
 *
 * Thread-confinement: the mutable {@see PhiAccrualDetector} and the
 * {@see PeerSelector} are actor collaborators (constructor-injected), not part
 * of the replaceable state — they are only ever touched on the actor's own
 * message-processing path, so no locking is required.
 *
 * Scheduling: {@see self::props()} arranges the HeartbeatTick / GossipTick
 * self-ticks via `scheduleRepeatedly` when the actor starts.
 *
 * @implements StatefulActorHandler<object, MembershipState>
 */
final class MembershipActor implements StatefulActorHandler
{
    private readonly Duration $heartbeatInterval;

    private readonly Duration $gossipInterval;

    private ?Counter $nodesSuspected = null;

    private ?Counter $nodesRecovered = null;

    private ?Counter $nodesPruned = null;

    private ?Counter $heartbeatsReceived = null;

    public function __construct(
        private readonly MembershipService $service,
        private readonly PhiAccrualDetector $detector,
        private readonly PeerSelector $selector,
        private readonly MembershipEffectInterpreter $effectInterpreter,
        private readonly MembershipEventPublisher $eventPublisher,
        private readonly ClockInterface $clock,
        ?Duration $heartbeatInterval = null,
        ?Duration $gossipInterval = null,
        private readonly Meter $meter = new NoopMeter(),
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly MembershipEventDeduplicator $deduplicator = new MembershipEventDeduplicator(),
    ) {
        $this->heartbeatInterval = $heartbeatInterval ?? Duration::seconds(1);
        $this->gossipInterval = $gossipInterval ?? Duration::seconds(1);
    }

    /**
     * Build the actor's Props: schedules the two self-ticks on startup and runs
     * under a restart supervision strategy (a crashing membership loop should
     * recover rather than tear the node down). C1.6e may pass a custom strategy.
     *
     * @return Props<object>
     * @psalm-suppress InvalidArgument Psalm cannot infer the message template through the nested setup→withState closures.
     */
    public function props(?SupervisionStrategy $supervision = null): Props
    {
        $actor = $this;
        $heartbeatInterval = $this->heartbeatInterval;
        $gossipInterval = $this->gossipInterval;

        $behavior = Behavior::setup(
            static function (ActorContext $ctx) use ($actor, $heartbeatInterval, $gossipInterval): Behavior {
                $hb = $ctx->scheduleRepeatedly($heartbeatInterval, $heartbeatInterval, new HeartbeatTick());
                $gp = $ctx->scheduleRepeatedly($gossipInterval, $gossipInterval, new GossipTick());

                return Behavior::withState(
                    $actor->initialState(),
                    static function (ActorContext $c, object $msg, mixed $state) use ($actor): BehaviorWithState {
                        /** @var MembershipState $typedState */
                        $typedState = $state;

                        return $actor->handle($c, $msg, $typedState);
                    },
                )->onSignal(static function (ActorContext $ctx, Signal $signal) use ($hb, $gp): Behavior {
                    if ($signal instanceof PostStop || $signal instanceof PreRestart) {
                        $hb->cancel();
                        $gp->cancel();
                    }

                    return Behavior::same();
                });
            },
        );

        return Props::fromBehavior($behavior)
            ->withSupervision($supervision ?? SupervisionStrategy::oneForOne());
    }

    /**
     * @return MembershipState
     */
    #[Override]
    public function initialState(): mixed
    {
        return MembershipState::fromTransition($this->service->initialState($this->clock->now()));
    }

    /**
     * @psalm-suppress MixedReturnTypeCoercion BehaviorWithState::same() erases the state template to mixed.
     */
    #[Override]
    public function handle(ActorContext $ctx, object $message, mixed $state): BehaviorWithState
    {
        $now = $this->clock->now();

        if ($message instanceof PeerLivenessObserved) {
            $this->safely(fn(): mixed => $this->heartbeatsReceivedCounter()->add(1));
        }

        return match (true) {
            $message instanceof HandshakeReceived => $this->apply($this->service->applyHandshake(
                $state->view,
                $state->suspectSince,
                $state->selfIncarnation,
                $this->detector,
                $message->origin,
                $message->endpoint,
                $message->handshake->clusterName,
                $message->handshake->protocolVersion,
                ClusterView::empty(),
                $message->observedAt,
                $now,
            )),
            $message instanceof GossipReceived => $this->apply($this->service->applyGossip(
                $state->view,
                $state->suspectSince,
                $state->selfIncarnation,
                $message->origin,
                $message->gossip,
                $now,
            )),
            $message instanceof PeerLivenessObserved => $this->apply($this->service->applyLiveness(
                $state->view,
                $state->suspectSince,
                $state->selfIncarnation,
                $this->detector,
                $message->peer,
                $message->endpoint,
                $message->observedAt,
                $now,
            )),
            $message instanceof PeerLinkClosed => $this->apply($this->service->applyLinkClosed(
                $state->view,
                $state->suspectSince,
                $state->selfIncarnation,
                $message->peer,
                $message->intentional,
                $now,
            )),
            $message instanceof LeaveReceived => $this->apply($this->service->applyLeave(
                $state->view,
                $state->suspectSince,
                $state->selfIncarnation,
                $message->origin,
            )),
            $message instanceof HeartbeatTick, $message instanceof GossipTick => $this->apply($this->service->applyTick(
                $state->view,
                $state->suspectSince,
                $state->selfIncarnation,
                $this->detector,
                $this->selector,
                $now,
            )),
            $message instanceof GetClusterView => $this->replyView($message, $state),
            default => BehaviorWithState::same(),
        };
    }

    /**
     * Publish every emitted event, interpret every produced effect, and adopt
     * the transition's new state.
     *
     * @return BehaviorWithState<object, MembershipState>
     */
    private function apply(MembershipTransition $transition): BehaviorWithState
    {
        $now = $this->clock->now();

        foreach ($transition->events as $event) {
            if ($event instanceof NodeDown) {
                // A peer that reached Down — by phi/silence timeout, a graceful Leave, or a gossiped
                // Down — has departed. Drop its failure-detector window here (the one place every
                // Down path funnels through) so a later rejoin starts with clean phi state instead of
                // recording one downtime-sized inter-arrival sample that desensitises the detector.
                $this->detector->forget($event->node->toPathPrefix());
            }

            if (!$this->shouldPublishEvent($event, $transition->newView, $now)) {
                continue;
            }

            $this->eventPublisher->publish($event);
            $this->recordMembershipEvent($event);
        }

        foreach ($transition->effects as $effect) {
            $this->effectInterpreter->interpret($effect);
        }

        return BehaviorWithState::next(MembershipState::fromTransition($transition));
    }

    /**
     * Gate an announcement through the {@see MembershipEventDeduplicator}: gossip view
     * merges re-emit the SAME (peer, incarnation, status) news on every merge until the
     * suspected node's incarnation refutation propagates, amplifying one transient into
     * dozens of events across a mesh. The view transition itself is never filtered —
     * only what is published and counted.
     */
    private function shouldPublishEvent(MembershipEvent $event, ClusterView $view, DateTimeImmutable $now): bool
    {
        [$node, $status] = match (true) {
            $event instanceof NodeUp => [$event->node, 'up'],
            $event instanceof NodeSuspected => [$event->node, 'suspected'],
            $event instanceof NodeDown => [$event->node, 'down'],
            default => [null, ''],
        };

        if ($node === null) {
            return true;
        }

        $key = $node->toPathPrefix();
        // A Down member has already been removed from the view — fall back to the last
        // incarnation this deduplicator announced for the peer.
        $incarnation = isset($view->members[$key])
            ? $view->members[$key]->incarnation
            : $this->deduplicator->lastKnownIncarnation($key);

        return $this->deduplicator->shouldPublish($key, $incarnation, $status, $now);
    }

    /**
     * @return BehaviorWithState<object, MembershipState>
     * @psalm-suppress MixedReturnTypeCoercion BehaviorWithState::same() erases the state template to mixed.
     */
    private function replyView(GetClusterView $message, MembershipState $state): BehaviorWithState
    {
        $message->replyTo->tell($state->view);

        return BehaviorWithState::same();
    }

    private function recordMembershipEvent(MembershipEvent $event): void
    {
        if ($event instanceof NodeSuspected) {
            $this->safely(fn(): mixed => $this->nodesSuspectedCounter()->add(1));
            $this->safely(fn(): mixed => $this->logger->info('cluster.membership.node_suspected', [
                'peer' => $event->node->toPathPrefix(),
                'reason' => $event->reason->name,
            ]));
        } elseif ($event instanceof NodeUp) {
            $this->safely(fn(): mixed => $this->nodesRecoveredCounter()->add(1));
            $this->safely(fn(): mixed => $this->logger->info('cluster.membership.node_up', [
                'peer' => $event->node->toPathPrefix(),
            ]));
        } elseif ($event instanceof NodeDown) {
            $this->safely(fn(): mixed => $this->nodesPrunedCounter()->add(1));
            $this->safely(fn(): mixed => $this->logger->info('cluster.membership.node_down', [
                'peer' => $event->node->toPathPrefix(),
            ]));
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
            // Telemetry must never break membership operations.
        }
    }

    private function nodesSuspectedCounter(): Counter
    {
        return $this->nodesSuspected ??= $this->meter->counter(
            'nexus.cluster.nodes.suspected',
            '{node}',
            'Cluster nodes that became suspected (unreachable)',
        );
    }

    private function nodesRecoveredCounter(): Counter
    {
        return $this->nodesRecovered ??= $this->meter->counter(
            'nexus.cluster.nodes.recovered',
            '{node}',
            'Cluster nodes that recovered from Suspect to Up',
        );
    }

    private function nodesPrunedCounter(): Counter
    {
        return $this->nodesPruned ??= $this->meter->counter(
            'nexus.cluster.nodes.pruned',
            '{node}',
            'Cluster nodes declared down and pruned from the view',
        );
    }

    private function heartbeatsReceivedCounter(): Counter
    {
        return $this->heartbeatsReceived ??= $this->meter->counter(
            'nexus.cluster.heartbeats.received',
            '{heartbeat}',
            'Heartbeat (liveness) signals received from peers',
        );
    }
}
