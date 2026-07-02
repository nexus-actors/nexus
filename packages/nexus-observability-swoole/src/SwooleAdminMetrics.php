<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Swoole;

use Monadial\Nexus\Observability\Observability;
use Swoole\Coroutine;
use Swoole\Server;

use function is_numeric;

/**
 * @psalm-api
 *
 * Registers Swoole server/coroutine statistics as OpenTelemetry observable
 * gauges (collected on demand by the metric reader). No-op when observability
 * is disabled. All gauges are server-wide (no high-cardinality dimensions).
 * Register the gauges once per worker at startup — calling a register* method
 * more than once registers duplicate instruments.
 */
final class SwooleAdminMetrics
{
    public function __construct(
        private readonly Observability $observability,
    ) {}

    public function registerCoroutineGauges(): void
    {
        if (!$this->observability->isEnabled()) {
            return;
        }

        $meter = $this->observability->meter();

        $meter->observableGauge(
            'swoole.coroutine.count',
            static fn (): int => self::stat(Coroutine::stats(), 'coroutine_num'),
            '{coroutine}',
            'Number of running Swoole coroutines',
        );
        $meter->observableGauge(
            'swoole.coroutine.peak',
            static fn (): int => self::stat(Coroutine::stats(), 'coroutine_peak_num'),
            '{coroutine}',
            'Peak number of concurrent Swoole coroutines',
        );
    }

    public function registerServerGauges(Server $server): void
    {
        if (!$this->observability->isEnabled()) {
            return;
        }

        $meter = $this->observability->meter();

        $meter->observableGauge(
            'swoole.server.connections',
            static fn (): int => self::stat($server->stats(), 'connection_num'),
            '{connection}',
            'Active Swoole server connections',
        );
        $meter->observableGauge(
            'swoole.server.requests',
            static fn (): int => self::stat($server->stats(), 'request_count'),
            '{request}',
            'Total requests handled by the Swoole server',
        );
        $meter->observableGauge(
            'swoole.server.workers.idle',
            static fn (): int => self::stat($server->stats(), 'idle_worker_num'),
            '{worker}',
            'Idle Swoole worker processes',
        );
    }

    /**
     * @param array<string, mixed> $stats
     */
    private static function stat(array $stats, string $key): int
    {
        $value = $stats[$key] ?? 0;

        return is_numeric($value)
            ? (int) $value
            : 0;
    }
}
