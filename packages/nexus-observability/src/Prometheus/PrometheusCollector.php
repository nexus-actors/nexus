<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Prometheus;

use Monadial\Nexus\Core\Actor\Telemetry\ActorSnapshot;
use Monadial\Nexus\Core\Actor\Telemetry\ActorSystemSnapshot;
use Monadial\Nexus\Runtime\Swoole\Telemetry\SwooleRuntimeSnapshot;
use Prometheus\CollectorRegistry;
use Prometheus\Gauge;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\InMemory;

/**
 * @psalm-api
 *
 * Populates a Prometheus registry from actor system and runtime snapshots.
 *
 * Each PrometheusCollector instance owns its own InMemory registry — create a
 * fresh instance per render cycle. Call collect() once per worker (with a
 * worker label) then call render() to produce Prometheus text format output.
 *
 * Standalone usage (no worker label):
 *     $c = new PrometheusCollector();
 *     $c->collect($system->snapshot(), $runtime->snapshot());
 *     echo $c->render();
 *
 * Worker pool usage (one collect per worker):
 *     $c = new PrometheusCollector();
 *     foreach ($aggregation->entries as $entry) {
 *         $c->collect($entry->system, $entry->runtime, (string) $entry->workerId);
 *     }
 *     echo $c->render();
 */
final class PrometheusCollector
{
    private CollectorRegistry $registry;

    private Gauge $mailboxDepth;

    private Gauge $actorAlive;

    private Gauge $coroutineNum;

    private Gauge $coroutinePeakNum;

    private Gauge $activeTimers;

    private Gauge $memoryBytes;

    private Gauge $memoryPeakBytes;

    private Gauge $deadLettersCount;

    public function __construct()
    {
        $this->registry = new CollectorRegistry(new InMemory());

        $this->mailboxDepth = $this->registry->getOrRegisterGauge(
            'nexus',
            'actor_mailbox_depth',
            'Current number of messages in actor mailbox',
            ['system', 'actor', 'worker'],
        );
        $this->actorAlive = $this->registry->getOrRegisterGauge(
            'nexus',
            'actor_alive',
            'Whether the actor is alive (1=yes, 0=no)',
            ['system', 'actor', 'worker'],
        );
        $this->coroutineNum = $this->registry->getOrRegisterGauge(
            'nexus',
            'coroutine_num',
            'Current number of Swoole coroutines',
            ['system', 'worker'],
        );
        $this->coroutinePeakNum = $this->registry->getOrRegisterGauge(
            'nexus',
            'coroutine_peak_num',
            'Peak number of Swoole coroutines',
            ['system', 'worker'],
        );
        $this->activeTimers = $this->registry->getOrRegisterGauge(
            'nexus',
            'active_timers',
            'Number of active Swoole timers',
            ['system', 'worker'],
        );
        $this->memoryBytes = $this->registry->getOrRegisterGauge(
            'nexus',
            'memory_bytes',
            'Current memory usage in bytes',
            ['system', 'worker'],
        );
        $this->memoryPeakBytes = $this->registry->getOrRegisterGauge(
            'nexus',
            'memory_peak_bytes',
            'Peak memory usage in bytes',
            ['system', 'worker'],
        );
        $this->deadLettersCount = $this->registry->getOrRegisterGauge(
            'nexus',
            'dead_letters_total',
            'Total number of dead letters received',
            ['system', 'worker'],
        );
    }

    /**
     * Collect metrics from one actor system + runtime pair.
     *
     * @param string $worker Empty string for standalone, worker ID for pool.
     *
     * @psalm-suppress InvalidOperand
     */
    public function collect(
        ActorSystemSnapshot $system,
        SwooleRuntimeSnapshot $runtime,
        string $worker = '',
    ): void {
        $name = $system->systemName;

        foreach ($system->actors as $actor) {
            $this->collectActor($actor, $name, $worker);
        }

        $this->coroutineNum->set((float) $runtime->coroutineNum, [$name, $worker]);
        $this->coroutinePeakNum->set((float) $runtime->coroutinePeakNum, [$name, $worker]);
        $this->activeTimers->set((float) $runtime->activeTimers, [$name, $worker]);
        $this->memoryBytes->set((float) $runtime->memoryBytes, [$name, $worker]);
        $this->memoryPeakBytes->set((float) $runtime->memoryPeakBytes, [$name, $worker]);
        $this->deadLettersCount->set((float) $system->deadLettersCount, [$name, $worker]);
    }

    public function render(): string
    {
        $renderer = new RenderTextFormat();

        return $renderer->render($this->registry->getMetricFamilySamples());
    }

    /**
     * @psalm-suppress InvalidOperand
     */
    private function collectActor(ActorSnapshot $actor, string $systemName, string $worker): void
    {
        $path = $actor->path;

        $this->mailboxDepth->set((float) $actor->mailboxDepth, [$systemName, $path, $worker]);
        $this->actorAlive->set($actor->alive ? 1.0 : 0.0, [$systemName, $path, $worker]);

        foreach ($actor->children as $child) {
            $this->collectActor($child, $systemName, $worker);
        }
    }
}
