<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel\Export;

use Closure;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Lifecycle\PostStop;
use Monadial\Nexus\Core\Lifecycle\Signal;
use Monadial\Nexus\Core\Supervision\SupervisionStrategy;
use Monadial\Nexus\Observability\Metric\Meter;
use Monadial\Nexus\Observability\Metric\NoopMeter;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Mailbox\MailboxConfig;
use Monadial\Nexus\Runtime\Mailbox\OverflowStrategy;
use OpenTelemetry\SDK\Logs\LogRecordExporterInterface;
use OpenTelemetry\SDK\Metrics\PushMetricExporterInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * @psalm-api
 *
 * Owns all OTLP flush I/O. Every SDK span/metric/log batch handed to the observability
 * bridge is forwarded here as an {@see ExportSpans}, {@see ExportMetrics}, or
 * {@see ExportLogs} message so exporter I/O runs on the actor's own message-processing
 * path instead of blocking the caller. A failing inner exporter drops only the batch
 * that triggered it — the actor keeps running under exponential-backoff supervision.
 */
final readonly class OtlpExportActor
{
    public function __construct(
        private SpanExporterInterface $spans,
        private ?PushMetricExporterInterface $metrics,
        private ?LogRecordExporterInterface $logs,
        private Meter $meter = new NoopMeter(),
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * @return Props<ExportCommand>
     */
    public function props(): Props
    {
        // Re-typed to the full receive signature it satisfies: handle() takes `object`
        // (strays fall through its match) and returns Behavior<ExportCommand>.
        /** @var Closure(ActorContext<ExportCommand>, ExportCommand): Behavior<ExportCommand> $handler */
        $handler = fn(ActorContext $ctx, object $msg): Behavior => $this->handle($msg);

        // onSignal() returns a clone of the same generic behavior, but its bare
        // `static` return type erases the type param — re-type the composed result.
        /** @var Behavior<ExportCommand> $behavior */
        $behavior = Behavior::receive($handler)
            ->onSignal(function (ActorContext $ctx, Signal $signal): Behavior {
                if ($signal instanceof PostStop) {
                    $this->flushAll();
                }

                /** @var Behavior<ExportCommand> SameBehavior carries no runtime T; valid for any message type */
                return Behavior::same();
            });

        return Props::fromBehavior($behavior)
            ->withMailbox(MailboxConfig::bounded(256, OverflowStrategy::DropOldest))
            ->withSupervision(SupervisionStrategy::exponentialBackoff(
                initialBackoff: Duration::millis(100),
                maxBackoff: Duration::seconds(5),
            ));
    }

    /**
     * Takes `object` (not {@see ExportCommand}) so a stray message falls through the
     * match's default arm instead of fataling the actor with a TypeError.
     *
     * @return Behavior<ExportCommand>
     */
    private function handle(object $msg): Behavior
    {
        match (true) {
            $msg instanceof ExportSpans => $this->exportSpans($msg),
            $msg instanceof ExportMetrics => $this->exportMetrics($msg),
            $msg instanceof ExportLogs => $this->exportLogs($msg),
            $msg instanceof FlushNow => $this->flushAll(),
            default => null,
        };

        /** @var Behavior<ExportCommand> SameBehavior carries no runtime T; valid for any message type */
        return Behavior::same();
    }

    private function exportSpans(ExportSpans $msg): void
    {
        try {
            $this->spans->export($msg->batch)->await();
        } catch (Throwable $exception) {
            $this->drop('spans', $exception);
        }
    }

    private function exportMetrics(ExportMetrics $msg): void
    {
        if ($this->metrics === null) {
            return;
        }

        try {
            $this->metrics->export($msg->batch);
        } catch (Throwable $exception) {
            $this->drop('metrics', $exception);
        }
    }

    private function exportLogs(ExportLogs $msg): void
    {
        if ($this->logs === null) {
            return;
        }

        try {
            $this->logs->export($msg->batch)->await();
        } catch (Throwable $exception) {
            $this->drop('logs', $exception);
        }
    }

    private function drop(string $signal, Throwable $exception): void
    {
        $this->meter->counter('nexus.observability.export.dropped')->add(1.0, [
            'reason' => 'export_failed',
            'signal' => $signal,
        ]);

        $this->logger->debug('OtlpExportActor: dropped export batch', [
            'exception' => $exception,
            'signal' => $signal,
        ]);
    }

    private function flushAll(): void
    {
        $this->spans->forceFlush();
        $this->metrics?->forceFlush();
        $this->logs?->forceFlush();
    }
}
