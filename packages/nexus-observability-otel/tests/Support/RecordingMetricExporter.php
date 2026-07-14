<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel\Tests\Support;

use OpenTelemetry\SDK\Metrics\PushMetricExporterInterface;
use Override;
use RuntimeException;

use function iterator_to_array;

final class RecordingMetricExporter implements PushMetricExporterInterface
{
    public bool $throwOnExport = false;

    public int $forceFlushes = 0;

    public int $shutdowns = 0;

    /** @var list<array<array-key, mixed>> */
    public array $exported = [];

    #[Override]
    public function export(iterable $batch): bool
    {
        if ($this->throwOnExport) {
            throw new RuntimeException('RecordingMetricExporter: simulated export failure');
        }

        $this->exported[] = iterator_to_array($batch, false);

        return true;
    }

    #[Override]
    public function shutdown(): bool
    {
        $this->shutdowns++;

        return true;
    }

    #[Override]
    public function forceFlush(): bool
    {
        $this->forceFlushes++;

        return true;
    }
}
