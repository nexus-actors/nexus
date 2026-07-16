<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel\Export;

use OpenTelemetry\SDK\Metrics\Data\Metric;

/**
 * @psalm-api
 *
 * A batch of immutable SDK metric data handed off to the OtlpExportActor. Untraced so the
 * export path generates no telemetry about itself.
 */
final readonly class ExportMetrics implements ExportCommand
{
    /** @param list<Metric> $batch */
    public function __construct(public array $batch) {}
}
