<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel\Export;

use Monadial\Nexus\Core\Actor\UntracedMessage;

/**
 * @psalm-api
 *
 * Signal to the OtlpExportActor to flush any pending exports. Untraced so the export path
 * generates no telemetry about itself.
 */
final readonly class FlushNow implements UntracedMessage {}
