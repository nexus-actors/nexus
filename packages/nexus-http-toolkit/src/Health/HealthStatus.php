<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Toolkit\Health;

/**
 * @psalm-api
 *
 * Immutable result of a single HealthCheck.
 *
 * Three states inspired by Kubernetes / Spring Boot Actuator:
 *   - up           — check passed
 *   - degraded     — works but slow / partial / using fallback
 *   - down         — failed; /health returns 503 if any check is down
 *
 * `detail` is a free-form scalar map serialised into the /health JSON
 * response so dashboards can surface backing-service info (last seen
 * timestamps, hit ratios, queue depths) without parsing log lines.
 */
final readonly class HealthStatus
{
    /** @param array<string, scalar|null> $detail */
    public function __construct(public State $state, public array $detail = []) {}

    /** @param array<string, scalar|null> $detail */
    public static function up(array $detail = []): self
    {
        return new self(State::Up, $detail);
    }

    /** @param array<string, scalar|null> $detail */
    public static function degraded(array $detail = []): self
    {
        return new self(State::Degraded, $detail);
    }

    /** @param array<string, scalar|null> $detail */
    public static function down(array $detail = []): self
    {
        return new self(State::Down, $detail);
    }

    public function isUp(): bool
    {
        return $this->state === State::Up;
    }
}
