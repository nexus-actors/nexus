<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Metrics;

use Override;

/**
 * @psalm-api
 *
 * Default `MetricsCollector` impl — discards every emission. Wired by
 * `BusBuilder` when no adapter is registered, so middleware can call into
 * `MetricsCollector` unconditionally without a `null` check.
 */
final class NoOpMetricsCollector implements MetricsCollector
{
    #[Override]
    public function count(string $name, int $delta, array $tags): void
    {
        // intentional no-op — null-object metrics adapter.
    }

    #[Override]
    public function histogram(string $name, float $value, array $tags): void
    {
        // intentional no-op — null-object metrics adapter.
    }

    #[Override]
    public function gauge(string $name, float $value, array $tags): void
    {
        // intentional no-op — null-object metrics adapter.
    }
}
