<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Connection;

/**
 * @psalm-api
 *
 * Mutable single-slot holder for the latest published {@see RoutingSnapshot}. `publish()` is a
 * plain property swap, and `current()` a plain property read — no lock, no queue. This is safe
 * under both FiberRuntime and SwooleRuntime because PHP's cooperative scheduling never preempts
 * mid-statement: a reader always observes either the previous snapshot or the fully-constructed
 * new one, never a partially-published one.
 *
 * One holder instance is shared between {@see ConnectionSupervisor} (the sole writer) and
 * `ClusterNode` (the reader, on the egress and admission-check paths).
 */
final class RoutingSnapshotHolder
{
    private RoutingSnapshot $current;

    public function __construct(?RoutingSnapshot $initial = null)
    {
        $this->current = $initial ?? RoutingSnapshot::empty();
    }

    public function current(): RoutingSnapshot
    {
        return $this->current;
    }

    public function publish(RoutingSnapshot $snapshot): void
    {
        $this->current = $snapshot;
    }
}
