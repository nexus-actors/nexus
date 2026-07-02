<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Doctrine;

use Monadial\Nexus\Doctrine\Dbal\Event\ConnectionCreated;
use Monadial\Nexus\Doctrine\Dbal\Event\ConnectionDestroyed;
use Monadial\Nexus\Doctrine\Dbal\Event\ConnectionPoisoned;
use Monadial\Nexus\Doctrine\Dbal\Event\ConnectionReleased;
use Monadial\Nexus\Doctrine\Dbal\Event\ConnectionTaken;
use Monadial\Nexus\Doctrine\Dbal\Event\PoolExhausted;
use Monadial\Nexus\Observability\Metric\Counter;
use Monadial\Nexus\Observability\Metric\Histogram;
use Monadial\Nexus\Observability\Observability;

/**
 * @psalm-api
 *
 * PSR-14 listener recording DBAL connection-pool metrics. Register each
 * `on*` method for its event. No-op when observability is disabled. Metric
 * dimension is the bounded `pool.name`.
 */
final class DbalPoolMetricsListener
{
    /** @var array<string, Counter> */
    private array $counters = [];

    private ?Histogram $acquireWait = null;

    public function __construct(
        private readonly Observability $observability,
    ) {}

    public function onConnectionCreated(ConnectionCreated $event): void
    {
        $this->count('nexus.dbal.pool.connections.created', $event->poolName);
    }

    public function onConnectionTaken(ConnectionTaken $event): void
    {
        if (!$this->observability->isEnabled()) {
            return;
        }

        $this->count('nexus.dbal.pool.connections.taken', $event->poolName);
        $this->acquireWaitHistogram()->record(
            $event->waitTime->toSecondsFloat(),
            ['pool.name' => $event->poolName],
        );
    }

    public function onConnectionReleased(ConnectionReleased $event): void
    {
        $this->count('nexus.dbal.pool.connections.released', $event->poolName);
    }

    public function onConnectionDestroyed(ConnectionDestroyed $event): void
    {
        $this->count('nexus.dbal.pool.connections.destroyed', $event->poolName);
    }

    public function onConnectionPoisoned(ConnectionPoisoned $event): void
    {
        $this->count('nexus.dbal.pool.connections.poisoned', $event->poolName);
    }

    public function onPoolExhausted(PoolExhausted $event): void
    {
        $this->count('nexus.dbal.pool.exhausted', $event->poolName);
    }

    private function count(string $name, string $poolName): void
    {
        if (!$this->observability->isEnabled()) {
            return;
        }

        ($this->counters[$name] ??= $this->observability->meter()->counter($name, '{connection}', 'DBAL connection pool events'))
            ->add(1, ['pool.name' => $poolName]);
    }

    /** Caller must ensure observability is enabled. */
    private function acquireWaitHistogram(): Histogram
    {
        return $this->acquireWait ??= $this->observability->meter()->histogram(
            'nexus.dbal.pool.acquire.wait',
            's',
            'Time spent waiting to acquire a pooled DBAL connection',
        );
    }
}
