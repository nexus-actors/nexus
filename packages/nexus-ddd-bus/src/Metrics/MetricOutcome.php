<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Metrics;

/**
 * @psalm-api
 *
 * Lock outcomes here so the count tags shape is deterministic. Adapter
 * packages depend on these names verbatim (per umbrella spec §23.3).
 */
enum MetricOutcome: string
{
    case Started = 'started';
    case Succeeded = 'succeeded';
    case ValidationFailed = 'validation_failed';
    case AccessDenied = 'access_denied';
    case IdempotentShortCircuit = 'idempotent_short_circuit';
    case OccRetryExhausted = 'occ_retry_exhausted';
    case TerminalFailure = 'terminal_failure';
}
