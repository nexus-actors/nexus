<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel\Export;

use OpenTelemetry\SDK\Logs\LogRecordExporterInterface;
use OpenTelemetry\SDK\Metrics\PushMetricExporterInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;

/**
 * @psalm-api
 *
 * Bundles the forwarding exporters and the real inner exporters they wrap when
 * {@see \Monadial\Nexus\Observability\Config\ObservabilityConfig::$asyncExport} is enabled.
 * Passed into {@see \Monadial\Nexus\Observability\Otel\OtelObservability} so it can spawn the
 * {@see OtlpExportActor} and attach the forwarders once an {@see \Monadial\Nexus\Core\Actor\ActorSystem}
 * is available.
 */
final readonly class AsyncExportHandles
{
    public function __construct(
        public ?ActorForwardingSpanExporter $spans,
        public ?ActorForwardingMetricExporter $metrics,
        public ?ActorForwardingLogRecordExporter $logs,
        public SpanExporterInterface $innerSpans,
        public ?PushMetricExporterInterface $innerMetrics,
        public ?LogRecordExporterInterface $innerLogs,
    ) {}
}
