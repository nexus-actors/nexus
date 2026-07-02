<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Logger;

use Monadial\Nexus\Logger\Record;
use Monadial\Nexus\Logger\RecordProcessor;
use Monadial\Nexus\Observability\Observability;
use Override;
use Throwable;

/**
 * @psalm-api
 *
 * Stamps the active OpenTelemetry span's `trace_id`, `span_id`, and
 * `trace_flags` onto each log record's `extra`, so log lines correlate with
 * their trace. Runs synchronously on the caller's thread, so the ambient span
 * (the actor's Consumer span, or the HTTP Server span) is current. No-op when
 * observability is disabled, when no span is active, or on any telemetry error.
 */
final readonly class TraceCorrelationProcessor implements RecordProcessor
{
    public function __construct(
        private Observability $observability,
    ) {}

    #[Override]
    public function process(Record $record): Record
    {
        try {
            if (!$this->observability->isEnabled()) {
                return $record;
            }

            $spanContext = $this->observability->currentContext()->spanContext;

            if (!$spanContext->isValid()) {
                return $record;
            }

            return $record->withExtra([
                'span_id' => $spanContext->spanId,
                'trace_flags' => $spanContext->traceFlags,
                'trace_id' => $spanContext->traceId,
            ]);
        } catch (Throwable) {
            // Telemetry must never break logging.
            return $record;
        }
    }
}
