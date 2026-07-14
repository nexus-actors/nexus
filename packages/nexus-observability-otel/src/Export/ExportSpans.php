<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel\Export;

use Monadial\Nexus\Core\Actor\UntracedMessage;

/**
 * @psalm-api
 *
 * A batch of immutable SDK span data handed off to the OtlpExportActor. Untraced so the
 * export path generates no telemetry about itself.
 */
final readonly class ExportSpans implements UntracedMessage
{
    /** @param array<array-key, mixed> $batch */
    public function __construct(public array $batch) {}
}
