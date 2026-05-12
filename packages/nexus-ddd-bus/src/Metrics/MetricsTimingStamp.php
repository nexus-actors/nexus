<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Metrics;

use Monadial\Nexus\Ddd\Messaging\Envelope\Stamp;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Envelope stamp recording the dispatch start instant (`microtime(true)`).
 * Written by `MetricsStartMiddleware` at stage 4 of the canonical pipeline;
 * read by `MetricsEndMiddleware` at stage 12 to emit
 * `ddd.command.duration_ms` as a histogram on every dispatch (success
 * or terminal failure).
 */
final readonly class MetricsTimingStamp implements Stamp
{
    public function __construct(public float $startMicros) {}
}
