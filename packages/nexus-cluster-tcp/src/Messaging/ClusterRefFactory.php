<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Messaging;

use Closure;
use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Core\Actor\ActorPath;
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
    public function __construct(
        private NodeAddress $self,
        private OutboundSink $sink,
        private InboundDelivery $localDelivery,
        private TcpAskRegistry $askRegistry,
        private ClusterMessageCodec $codec,
        private TraceContextInjector $trace = new NoopTraceContextInjector(),
        private Tracer $tracer = new NoopTracer(),
    ) {}

    /**
     * @param Closure(): bool $aliveChecker Liveness probe (default: always alive). C1.6b
     *                                      wires this to the cluster {@see \Monadial\Nexus\Cluster\Tcp\Membership\ClusterView}.
     *
     * @return ClusterRef<object>
     */
    public function refFor(NodeAddress $target, ActorPath $targetPath, ?Closure $aliveChecker = null): ClusterRef
    {
        return new ClusterRef(
            $this->self,
            $target,
            $targetPath,
            $this->sink,
            $this->localDelivery,
            $this->askRegistry,
            $this->codec,
            $this->trace,
            $aliveChecker ?? static fn(): bool => true,
            $this->tracer,
        );
    }
}
