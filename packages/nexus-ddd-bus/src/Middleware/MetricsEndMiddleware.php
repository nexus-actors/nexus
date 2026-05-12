<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Middleware;

use Closure;
use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Bus\Exception\AccessDeniedException;
use Monadial\Nexus\Ddd\Bus\Exception\RetryBudgetExhaustedException;
use Monadial\Nexus\Ddd\Bus\Exception\ValidationFailedException;
use Monadial\Nexus\Ddd\Bus\Metrics\MetricOutcome;
use Monadial\Nexus\Ddd\Bus\Metrics\MetricsCollector;
use Monadial\Nexus\Ddd\Bus\Metrics\MetricsTimingStamp;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Exception\TerminalFailure;
use Override;
use Throwable;

use function microtime;

/**
 * @psalm-api
 *
 * Symmetric exit for `MetricsStartMiddleware`. Emits a single
 * `ddd.command.count` record on terminal outcomes (success or a known
 * terminal failure). Unclassified throwables propagate without emission —
 * upstream middleware (`OccRetryMiddleware` for retry exhaustion,
 * supervisor / adapter for infrastructure) owns those tags.
 *
 * On every exit (success, classified failure, unclassified rethrow), if
 * the envelope carries a `MetricsTimingStamp` written upstream by
 * `MetricsStartMiddleware`, emits `ddd.command.duration_ms` as a histogram
 * tagged with the concrete message FQN.
 *
 * @template TIn of object
 * @template TOut
 * @implements Middleware<TIn, TOut>
 */
final class MetricsEndMiddleware implements Middleware
{
    public function __construct(private readonly MetricsCollector $metrics) {}

    #[Override]
    public function process(Envelope $envelope, Closure $next): mixed
    {
        try {
            try {
                $result = $next($envelope);
            } catch (Throwable $e) {
                $type = $envelope->message::class;
                $metrics = $this->metrics;
                $this->classify($e)->tap(
                    static fn(MetricOutcome $outcome) => $metrics->count('ddd.command.count', 1, [
                        'outcome' => $outcome->value,
                        'type' => $type,
                    ]),
                );

                throw $e;
            }

            $this->metrics->count('ddd.command.count', 1, [
                'outcome' => MetricOutcome::Succeeded->value,
                'type' => $envelope->message::class,
            ]);

            return $result;
        } finally {
            $this->emitDurationHistogram($envelope);
        }
    }

    /**
     * @psalm-suppress InvalidOperand — `microtime(true) - $stamp->startMicros` mixes float with float; `* 1000` (int literal) triggers Psalm's strict int/float check, but the runtime result is a well-defined float duration in milliseconds.
     */
    private function emitDurationHistogram(Envelope $envelope): void
    {
        $metrics = $this->metrics;
        $type = $envelope->message::class;
        $envelope->get(MetricsTimingStamp::class)->tap(
            static function (MetricsTimingStamp $stamp) use ($metrics, $type): void {
                $durationMs = (microtime(true) - $stamp->startMicros) * 1000;
                $metrics->histogram('ddd.command.duration_ms', $durationMs, ['type' => $type]);
            },
        );
    }

    /** @return Option<MetricOutcome> */
    private function classify(Throwable $e): Option
    {
        return match (true) {
            $e instanceof ValidationFailedException => Option::some(MetricOutcome::ValidationFailed),
            $e instanceof AccessDeniedException => Option::some(MetricOutcome::AccessDenied),
            $e instanceof RetryBudgetExhaustedException => Option::some(MetricOutcome::OccRetryExhausted),
            $e instanceof TerminalFailure => Option::some(MetricOutcome::TerminalFailure),
            default => Option::none(),
        };
    }
}
