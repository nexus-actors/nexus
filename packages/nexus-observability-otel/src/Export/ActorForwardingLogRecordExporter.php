<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel\Export;

use Monadial\Nexus\Observability\Metric\Meter;
use Monadial\Nexus\Observability\Metric\NoopMeter;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use OpenTelemetry\SDK\Logs\LogRecordExporterInterface;
use Override;

use function iterator_to_array;

/**
 * @psalm-api
 *
 * Forwards log batches to the OtlpExportActor once attached, buffering ahead of
 * attachment and falling back to synchronous direct delegation if the actor dies.
 *
 * @see ForwardsBatchesToActor for the Buffering -> Live -> Direct state machine.
 */
// phpcs:ignore SlevomatCodingStandard.Classes.ReadonlyClass.ClassCanBeReadonly -- ForwardsBatchesToActor holds a mutable $buffer; the class cannot be readonly
final class ActorForwardingLogRecordExporter implements LogRecordExporterInterface
{

    use ForwardsBatchesToActor;

    public function __construct(
        private readonly LogRecordExporterInterface $inner,
        private readonly Meter $meter = new NoopMeter(),
    ) {}

    #[Override]
    public function export(iterable $batch, ?CancellationInterface $cancellation = null): FutureInterface
    {
        $this->forward(iterator_to_array($batch, false));

        return new CompletedFuture(true);
    }

    /**
     * @psalm-suppress MixedArgument buffered batches are opaque SDK payload data by design
     */
    #[Override]
    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        if ($this->isBuffering()) {
            $this->flushBufferSynchronously(function (array $buffered) use ($cancellation): void {
                foreach ($buffered as $message) {
                    $this->inner->export($message->batch, $cancellation)->await();
                }
            });

            return true;
        }

        if ($this->isLive()) {
            $this->tellFlushNow();

            return true;
        }

        return $this->inner->shutdown($cancellation);
    }

    #[Override]
    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        if ($this->isLive()) {
            $this->tellFlushNow();

            return true;
        }

        return $this->inner->forceFlush($cancellation);
    }

    /**
     * @param array<array-key, mixed> $batch
     */
    #[Override]
    private function wrap(array $batch): object
    {
        return new ExportLogs($batch);
    }

    /**
     * @param array<array-key, mixed> $batch
     *
     * @psalm-suppress PossiblyInvalidArgument batch is opaque SDK payload data by design
     */
    #[Override]
    private function exportDirect(array $batch): void
    {
        $this->inner->export($batch)->await();
    }

    #[Override]
    private function signalName(): string
    {
        return 'logs';
    }

    #[Override]
    private function meter(): Meter
    {
        return $this->meter;
    }
}
