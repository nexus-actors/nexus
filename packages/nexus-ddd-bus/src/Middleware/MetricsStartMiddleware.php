<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Middleware;

use Closure;
use Monadial\Nexus\Ddd\Bus\Metrics\MetricOutcome;
use Monadial\Nexus\Ddd\Bus\Metrics\MetricsCollector;
use Monadial\Nexus\Ddd\Bus\Metrics\MetricsTimingStamp;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Override;

use function microtime;

/**
 * @psalm-api
 *
 * Emits a `ddd.command.count` counter at the start of every dispatch with
 * `outcome=started` and `type=<message FQN>`. Adapter packages translate
 * the canonical tags to their backend (Prometheus, StatsD).
 *
 * Also stamps the envelope with a `MetricsTimingStamp` capturing the start
 * instant; `MetricsEndMiddleware` reads it to emit
 * `ddd.command.duration_ms` as a histogram.
 *
 * @template TIn of object
 * @template TOut
 * @implements Middleware<TIn, TOut>
 */
final class MetricsStartMiddleware implements Middleware
{
    public function __construct(private readonly MetricsCollector $metrics) {}

    #[Override]
    public function process(Envelope $envelope, Closure $next): mixed
    {
        $envelope = $envelope->with(new MetricsTimingStamp(microtime(true)));
        $this->metrics->count('ddd.command.count', 1, [
            'outcome' => MetricOutcome::Started->value,
            'type' => $envelope->message::class,
        ]);

        return $next($envelope);
    }
}
