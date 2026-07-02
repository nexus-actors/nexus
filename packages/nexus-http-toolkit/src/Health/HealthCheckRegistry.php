<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Toolkit\Health;

/**
 * @psalm-api
 *
 * Aggregates HealthCheck implementations. The HealthCheckHandler walks
 * the registry on every /health request, runs each check, and assembles
 * the overall status.
 *
 * Mutable on purpose — checks are usually registered at boot, before
 * the application starts serving, and the registry is then read-only
 * for the worker's lifetime.
 */
final class HealthCheckRegistry
{
    /** @var list<HealthCheck> */
    private array $checks = [];

    public function add(HealthCheck $check): self
    {
        $this->checks[] = $check;

        return $this;
    }

    /** @return list<HealthCheck> */
    public function all(): array
    {
        return $this->checks;
    }
}
