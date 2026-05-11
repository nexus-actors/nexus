<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Middleware;

/**
 * @psalm-api
 *
 * Canonical 14-stage pipeline. Lock the sequence here;
 * MiddlewareOrderingRule (Phase 17) validates that adopter-supplied
 * `before:` / `after:` arguments name an existing case.
 *
 * v2 split: stages 7a (IdempotencyReserve, outer; before OCC retry) and
 * 10 (IdempotencyCommit, inner; INSIDE handler TX, post-handler pre-flush).
 * Reserve runs OUTSIDE the OCC retry loop so retries reuse the same token;
 * Commit runs INSIDE the TX so it lands or rolls back atomically with the
 * handler's writes.
 */
enum PipelineStage: string
{
    case Causation = 'causation';
    case OtelSpan = 'otel-span';
    case LoggingStart = 'logging-start';
    case MetricsStart = 'metrics-start';
    case Validation = 'validation';
    case Authorization = 'authorization';
    case IdempotencyReserve = 'idempotency-reserve';
    case OccRetry = 'occ-retry';
    case Handler = 'handler';
    case IdempotencyCommit = 'idempotency-commit';
    case EventDrain = 'event-drain';
    case MetricsEnd = 'metrics-end';
    case LoggingEnd = 'logging-end';
    case SpanClose = 'span-close';

    /** @return list<string> */
    public static function names(): array
    {
        return array_map(static fn(self $s): string => $s->value, self::cases());
    }
}
