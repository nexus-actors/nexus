<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Actor;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Observability\Observability;

/**
 * @psalm-api
 *
 * Registers internal actor-system state as OpenTelemetry observable gauges,
 * collected on demand by the metric reader. No-op when observability is
 * disabled. Register once per system at startup — calling {@see self::register()}
 * more than once registers duplicate instruments.
 */
final readonly class ActorSystemMetrics
{
    public function __construct(private Observability $observability, private ActorSystem $system,) {}

    public function register(): void
    {
        if (!$this->observability->isEnabled()) {
            return;
        }

        $meter = $this->observability->meter();
        $system = $this->system;

        $meter->observableGauge(
            'nexus.actor_system.live_actors',
            static fn(): int => $system->liveActorCount(),
            '{actor}',
            'Number of live root actors in the system',
        );
        $meter->observableGauge(
            'nexus.actor_system.dead_letters',
            static fn(): int => $system->deadLetters()->total(),
            '{message}',
            'Total dead-lettered messages captured by the system',
        );
        $meter->observableGauge(
            'nexus.actor_system.running',
            static fn(): int => $system->isRunning()
                ? 1
                : 0,
            '{system}',
            'Whether the actor system runtime is running (1) or not (0)',
        );
    }
}
