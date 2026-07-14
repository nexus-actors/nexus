<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel\Export;

use Monadial\Nexus\Observability\Metric\Meter;
use Monadial\Nexus\Observability\Metric\NoopMeter;
use OpenTelemetry\SDK\Metrics\AggregationTemporalitySelectorInterface;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\MetricMetadataInterface;
use OpenTelemetry\SDK\Metrics\PushMetricExporterInterface;
use Override;

use function iterator_to_array;

/**
 * @psalm-api
 *
 * Forwards metric batches to the OtlpExportActor once attached, buffering ahead of
 * attachment and falling back to synchronous direct delegation if the actor dies.
 *
 * @see ForwardsBatchesToActor for the Buffering -> Live -> Direct state machine.
 */
// phpcs:ignore SlevomatCodingStandard.Classes.ReadonlyClass.ClassCanBeReadonly -- ForwardsBatchesToActor holds a mutable $buffer; the class cannot be readonly
final class ActorForwardingMetricExporter implements PushMetricExporterInterface, AggregationTemporalitySelectorInterface
{

    use ForwardsBatchesToActor;

    public function __construct(
        private readonly PushMetricExporterInterface $inner,
        private readonly Meter $meter = new NoopMeter(),
    ) {}

    #[Override]
    public function temporality(MetricMetadataInterface $metric): Temporality|string|null
    {
        if ($this->inner instanceof AggregationTemporalitySelectorInterface) {
            return $this->inner->temporality($metric);
        }

        return $metric->temporality();
    }

    #[Override]
    public function export(iterable $batch): bool
    {
        $this->forward(iterator_to_array($batch, false));

        return true;
    }

    /**
     * @psalm-suppress MixedArgument buffered batches are opaque SDK payload data by design
     */
    #[Override]
    public function shutdown(): bool
    {
        if ($this->isBuffering()) {
            $this->flushBufferSynchronously(function (array $buffered): void {
                foreach ($buffered as $message) {
                    $this->inner->export($message->batch);
                }
            });

            return true;
        }

        if ($this->isLive()) {
            $this->tellFlushNow();

            return true;
        }

        return $this->inner->shutdown();
    }

    #[Override]
    public function forceFlush(): bool
    {
        if ($this->isLive()) {
            $this->tellFlushNow();

            return true;
        }

        return $this->inner->forceFlush();
    }

    /**
     * @param array<array-key, mixed> $batch
     */
    #[Override]
    private function wrap(array $batch): object
    {
        return new ExportMetrics($batch);
    }

    /**
     * @param array<array-key, mixed> $batch
     *
     * @psalm-suppress PossiblyInvalidArgument batch is opaque SDK payload data by design
     */
    #[Override]
    private function exportDirect(array $batch): void
    {
        $this->inner->export($batch);
    }

    #[Override]
    private function signalName(): string
    {
        return 'metrics';
    }

    #[Override]
    private function meter(): Meter
    {
        return $this->meter;
    }
}
