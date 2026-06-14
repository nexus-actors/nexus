<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Toolkit\Health;

/**
 * @psalm-api
 *
 * One probe. Implementations return a HealthStatus describing whether
 * the check passed and an optional structured detail body.
 *
 * Keep checks fast — they are called synchronously on every /health
 * request. For expensive backends (cold remote dependencies), wrap the
 * check in an actor that polls in the background and caches the
 * latest result.
 */
interface HealthCheck
{
    /**
     * Human-readable identifier surfaced in the /health response payload.
     * Use a short kebab-case name: "database", "redis", "queue".
     */
    public function name(): string;

    public function check(): HealthStatus;
}
