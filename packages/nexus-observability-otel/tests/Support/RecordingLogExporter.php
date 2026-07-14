<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel\Tests\Support;

use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use OpenTelemetry\SDK\Logs\LogRecordExporterInterface;
use Override;
use RuntimeException;

use function iterator_to_array;

final class RecordingLogExporter implements LogRecordExporterInterface
{
    public bool $throwOnExport = false;

    public int $forceFlushes = 0;

    public int $shutdowns = 0;

    /** @var list<array<array-key, mixed>> */
    public array $exported = [];

    #[Override]
    public function export(iterable $batch, ?CancellationInterface $cancellation = null): FutureInterface
    {
        if ($this->throwOnExport) {
            throw new RuntimeException('RecordingLogExporter: simulated export failure');
        }

        $this->exported[] = iterator_to_array($batch, false);

        return new CompletedFuture(true);
    }

    #[Override]
    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        $this->forceFlushes++;

        return true;
    }

    #[Override]
    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        $this->shutdowns++;

        return true;
    }
}
