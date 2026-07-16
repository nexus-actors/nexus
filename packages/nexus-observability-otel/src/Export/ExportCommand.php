<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel\Export;

use Monadial\Nexus\Core\Actor\UntracedMessage;

/**
 * @psalm-api
 *
 * Marker for the messages accepted by the {@see OtlpExportActor} — the batch
 * envelopes ({@see ExportSpans}, {@see ExportMetrics}, {@see ExportLogs}) and the
 * {@see FlushNow} control signal. Gives the export actor's ref a concrete message
 * type (`ActorRef<ExportCommand>`). Untraced so the export path generates no
 * telemetry about itself.
 */
interface ExportCommand extends UntracedMessage {}
