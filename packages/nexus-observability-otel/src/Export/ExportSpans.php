<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel\Export;

use OpenTelemetry\SDK\Trace\SpanDataInterface;

/**
 * @psalm-api
 *
 * A batch of immutable SDK span data handed off to the OtlpExportActor. Untraced so the
 * export path generates no telemetry about itself.
 */
final readonly class ExportSpans implements ExportCommand
{
    /** @param list<SpanDataInterface> $batch */
    public function __construct(public array $batch) {}
}
