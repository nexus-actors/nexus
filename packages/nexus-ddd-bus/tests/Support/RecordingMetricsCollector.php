<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Support;

use Monadial\Nexus\Ddd\Bus\Metrics\MetricsCollector;
use Override;

/**
 * Test fixture: a `MetricsCollector` that captures every emission as a
 * `[kind, name, value, tags]` tuple. Tests assert against the public
 * arrays. No metrics backend (Prometheus, StatsD) enters the package.
 */
final class RecordingMetricsCollector implements MetricsCollector
{
    /** @var list<array{kind: string, name: string, tags: array<string, scalar>, value: int|float}> */
    public array $records = [];

    #[Override]
    public function count(string $name, int $delta, array $tags): void
    {
        $this->records[] = ['kind' => 'count', 'name' => $name, 'tags' => $tags, 'value' => $delta];
    }

    #[Override]
    public function histogram(string $name, float $value, array $tags): void
    {
        $this->records[] = ['kind' => 'histogram', 'name' => $name, 'tags' => $tags, 'value' => $value];
    }

    #[Override]
    public function gauge(string $name, float $value, array $tags): void
    {
        $this->records[] = ['kind' => 'gauge', 'name' => $name, 'tags' => $tags, 'value' => $value];
    }
}
