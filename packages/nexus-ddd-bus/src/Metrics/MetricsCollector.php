<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Metrics;

/**
 * @psalm-api
 *
 * Slot interface for application-supplied metrics adapters.
 *
 * Bus middleware emits canonical tags (`type` = the message FQN, `outcome`
 * = a `MetricOutcome` value). Adapter packages translate to Prometheus,
 * StatsD, or whatever the deployment runs.
 */
interface MetricsCollector
{
    /** @param array<string, scalar> $tags */
    public function count(string $name, int $delta, array $tags): void;

    /** @param array<string, scalar> $tags */
    public function histogram(string $name, float $value, array $tags): void;

    /** @param array<string, scalar> $tags */
    public function gauge(string $name, float $value, array $tags): void;
}
