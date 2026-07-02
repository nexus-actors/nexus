<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\WorkerPool;

use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Observability\Metric\Counter;
use Monadial\Nexus\Observability\Metric\Histogram;
use Monadial\Nexus\Observability\Observability;
use Monadial\Nexus\Observability\Trace\Span;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Observability\Trace\StatusCode;
use Monadial\Nexus\WorkerPool\Transport\WorkerTransport;
use Override;
use Throwable;

use function hrtime;

/**
 * @psalm-api
 *
 * Tracing decorator for a {@see WorkerTransport}. On send it opens a Producer
 * span, injects that span's context into the envelope's metadata so the trace
 * survives the worker-thread boundary (the receiving actor opens a Consumer
 * span from the metadata), and records transport metrics. Transport errors
 * propagate; telemetry never breaks a send; disabled → pure delegation.
 */
final class TracingWorkerTransport implements WorkerTransport
{
    private ?Counter $messagesSent = null;

    private ?Histogram $sendDuration = null;

    public function __construct(
        private readonly WorkerTransport $inner,
        private readonly Observability $observability,
    ) {}

    #[Override]
    public function send(int $targetWorker, Envelope $envelope): void
    {
        if (!$this->observability->isEnabled()) {
            $this->inner->send($targetWorker, $envelope);

            return;
        }

        $span = $this->startSpan($targetWorker, $envelope);
        $envelope = $this->inject($envelope);
        $start = hrtime(true);

        try {
            $this->inner->send($targetWorker, $envelope);
            $this->safely(fn (): mixed => $this->messagesSentCounter()->add(1, ['nexus.worker.target' => $targetWorker]));
        } catch (Throwable $e) {
            $this->recordError($span, $e);

            throw $e;
        } finally {
            $this->finishSpan($span, $targetWorker, $start);
        }
    }

    #[Override]
    public function listen(callable $onEnvelope): void
    {
        $this->inner->listen($onEnvelope);
    }

    #[Override]
    public function close(): void
    {
        $this->inner->close();
    }

    #[Override]
    public function stop(): void
    {
        $this->inner->stop();
    }

    #[Override]
    public function isStopped(): bool
    {
        return $this->inner->isStopped();
    }

    private function startSpan(int $targetWorker, Envelope $envelope): ?Span
    {
        try {
            return $this->observability->tracer()->startSpan(
                'worker.send',
                SpanKind::Producer,
                [
                    'messaging.operation' => 'send',
                    'messaging.system' => 'nexus',
                    'nexus.actor.path' => (string) $envelope->target,
                    'nexus.worker.target' => $targetWorker,
                ],
            );
        } catch (Throwable) {
            return null;
        }
    }

    private function inject(Envelope $envelope): Envelope
    {
        try {
            $carrier = $envelope->metadata;
            $this->observability->propagator()->inject($this->observability->currentContext(), $carrier);

            return $carrier === $envelope->metadata
                ? $envelope
                : $envelope->withMetadata($carrier);
        } catch (Throwable) {
            return $envelope;
        }
    }

    private function finishSpan(?Span $span, int $targetWorker, int $startNanos): void
    {
        $this->safely(static fn (): mixed => $span?->end());
        $this->safely(function () use ($targetWorker, $startNanos): void {
            $this->sendDurationHistogram()->record(
                (hrtime(true) - $startNanos) / 1_000_000_000,
                ['nexus.worker.target' => $targetWorker],
            );
        });
    }

    private function recordError(?Span $span, Throwable $e): void
    {
        $this->safely(static function () use ($span, $e): void {
            $span?->recordException($e);
            $span?->setStatus(StatusCode::Error, $e->getMessage());
        });
    }

    private function messagesSentCounter(): Counter
    {
        return $this->messagesSent ??= $this->observability->meter()->counter(
            'nexus.worker_pool.messages.sent',
            '{message}',
            'Messages sent across worker threads',
        );
    }

    private function sendDurationHistogram(): Histogram
    {
        return $this->sendDuration ??= $this->observability->meter()->histogram(
            'nexus.worker_pool.send.duration',
            's',
            'Duration of cross-worker transport sends',
        );
    }

    /**
     * @param callable(): mixed $fn
     */
    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable) {
            // Telemetry must never break transport.
        }
    }
}
