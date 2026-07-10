<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Messaging;

use Closure;
use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Observability\Metric\Meter;
use Monadial\Nexus\Observability\Metric\NoopMeter;
use Monadial\Nexus\Observability\Trace\NoopTracer;
use Monadial\Nexus\Observability\Trace\Tracer;

/**
 * @psalm-api
 *
 * Builds {@see ClusterRef} instances for target actors, injecting the shared messaging
 * collaborators (outbound sink, local delivery, ask registry, codec, trace seam, tracer) and
 * the sending node's own address so refs can short-circuit self-node sends.
 */
final readonly class ClusterRefFactory
{
    /**
     * @param Closure(NodeAddress): bool|null $isAlive Per-node liveness probe consulted by
     *        {@see ClusterRef::isAlive()}. Null (the default) means always-alive;
     *        {@see \Monadial\Nexus\Cluster\Tcp\ClusterNode::boot()} wires a probe backed by the
     *        membership {@see \Monadial\Nexus\Cluster\Tcp\Membership\DepartedPeerTracker}.
     */
    public function __construct(
        private NodeAddress $self,
        private OutboundSink $sink,
        private InboundDelivery $localDelivery,
        private TcpAskRegistry $askRegistry,
        private ClusterMessageCodec $codec,
        private TraceContextInjector $trace = new NoopTraceContextInjector(),
        private Tracer $tracer = new NoopTracer(),
        private Meter $meter = new NoopMeter(),
        private ?Closure $isAlive = null,
    ) {}

    /**
     * @param Closure(): bool|null $aliveChecker Optional per-ref liveness override. When null (the
     *        usual case), the factory binds its node-level `$isAlive` probe to `$target`.
     *
     * @return ClusterRef<object>
     */
    public function refFor(NodeAddress $target, ActorPath $targetPath, ?Closure $aliveChecker = null): ClusterRef
    {
        $isAlive = $this->isAlive;
        $bound = $aliveChecker
            ?? ($isAlive !== null
                ? static fn(): bool => $isAlive($target)
                : static fn(): bool => true);

        return new ClusterRef(
            $this->self,
            $target,
            $targetPath,
            $this->sink,
            $this->localDelivery,
            $this->askRegistry,
            $this->codec,
            $this->trace,
            $bound,
            $this->tracer,
            $this->meter,
        );
    }
}
