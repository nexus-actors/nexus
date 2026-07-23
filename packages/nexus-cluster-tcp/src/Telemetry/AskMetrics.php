<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Telemetry;

use Monadial\Nexus\Observability\Metric\Counter;
use Monadial\Nexus\Observability\Metric\Histogram;
use Monadial\Nexus\Observability\Metric\Meter;
use Monadial\Nexus\Observability\Metric\ObservableGauge;

/**
 * Ask/correlation instruments (spec §3.5). The pending gauge observes the
 * registry's live count via the injected callback — no lazy creation.
 */
final readonly class AskMetrics
{
    public Counter $asksSent;
    public Counter $asksResolved;
    public Counter $asksTimedOut;
    public Counter $asksCapacityRejected;
    public Histogram $askDuration;
    public ObservableGauge $asksPending;

    /**
     * @param callable(): int $pendingCount
     */
    public function __construct(Meter $meter, callable $pendingCount)
    {
        $this->asksSent = $meter->counter('nexus.cluster.asks.sent', '{message}', 'Remote asks initiated');
        $this->asksResolved = $meter->counter(
            'nexus.cluster.asks.resolved',
            '{message}',
            'Remote asks resolved with a reply',
        );
        $this->asksTimedOut = $meter->counter(
            'nexus.cluster.asks.timed_out',
            '{message}',
            'Remote asks that timed out',
        );
        $this->asksCapacityRejected = $meter->counter(
            'nexus.cluster.asks.capacity_rejected',
            '{message}',
            'Asks rejected at registry capacity',
        );
        $this->askDuration = $meter->histogram('nexus.cluster.ask.duration', 'ms', 'Remote ask round-trip duration');
        $this->asksPending = $meter->observableGauge(
            'nexus.cluster.asks.pending',
            $pendingCount,
            '{message}',
            'Asks currently awaiting a reply',
        );
    }
}
