<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Doctrine;

use Monadial\Nexus\Doctrine\Orm\Event\EntityManagerCleared;
use Monadial\Nexus\Doctrine\Orm\Event\EntityManagerCreated;
use Monadial\Nexus\Doctrine\Orm\Event\EntityManagerEvicted;
use Monadial\Nexus\Observability\Metric\Counter;
use Monadial\Nexus\Observability\Observability;

/**
 * @psalm-api
 *
 * PSR-14 listener recording ORM EntityManager-pool metrics. No-op when
 * disabled. Metric dimension is the bounded `pool.name`.
 */
final class OrmPoolMetricsListener
{
    /** @var array<string, Counter> */
    private array $counters = [];

    public function __construct(private readonly Observability $observability,) {}

    public function onEntityManagerCreated(EntityManagerCreated $event): void
    {
        $this->count('nexus.orm.pool.entity_managers.created', $event->poolName);
    }

    public function onEntityManagerCleared(EntityManagerCleared $event): void
    {
        $this->count('nexus.orm.pool.entity_managers.cleared', $event->poolName);
    }

    public function onEntityManagerEvicted(EntityManagerEvicted $event): void
    {
        $this->count('nexus.orm.pool.entity_managers.evicted', $event->poolName);
    }

    private function count(string $name, string $poolName): void
    {
        if (!$this->observability->isEnabled()) {
            return;
        }

        ($this->counters[$name] ??= $this->observability->meter()->counter(
            $name,
            '{entity_manager}',
            'ORM entity-manager pool events',
        ))
            ->add(1, ['pool.name' => $poolName]);
    }
}
