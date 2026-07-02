<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Persistence;

use Monadial\Nexus\Observability\Metric\Histogram;
use Monadial\Nexus\Observability\Observability;
use Monadial\Nexus\Observability\Trace\Span;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Observability\Trace\StatusCode;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\State\DurableStateEnvelope;
use Monadial\Nexus\Persistence\State\DurableStateStore;
use Override;
use Throwable;

use function hrtime;

/**
 * @psalm-api
 *
 * Tracing decorator for a {@see DurableStateStore}. Store errors propagate;
 * telemetry never breaks the operation; delegates when disabled.
 */
final class TracingDurableStateStore implements DurableStateStore
{
    private ?Histogram $operationDuration = null;

    public function __construct(
        private readonly DurableStateStore $inner,
        private readonly Observability $observability,
    ) {}

    #[Override]
    public function get(PersistenceId $id): ?DurableStateEnvelope
    {
        if (!$this->observability->isEnabled()) {
            return $this->inner->get($id);
        }

        $span = $this->startSpan('DurableStateStore.get', $id);
        $start = hrtime(true);

        try {
            return $this->inner->get($id);
        } catch (Throwable $e) {
            $this->recordError($span, $e);

            throw $e;
        } finally {
            $this->finishSpan($span, 'get', $id, $start);
        }
    }

    #[Override]
    public function upsert(PersistenceId $id, DurableStateEnvelope $state): void
    {
        if (!$this->observability->isEnabled()) {
            $this->inner->upsert($id, $state);

            return;
        }

        $span = $this->startSpan('DurableStateStore.upsert', $id);
        $start = hrtime(true);

        try {
            $this->inner->upsert($id, $state);
        } catch (Throwable $e) {
            $this->recordError($span, $e);

            throw $e;
        } finally {
            $this->finishSpan($span, 'upsert', $id, $start);
        }
    }

    #[Override]
    public function delete(PersistenceId $id): void
    {
        if (!$this->observability->isEnabled()) {
            $this->inner->delete($id);

            return;
        }

        $span = $this->startSpan('DurableStateStore.delete', $id);
        $start = hrtime(true);

        try {
            $this->inner->delete($id);
        } catch (Throwable $e) {
            $this->recordError($span, $e);

            throw $e;
        } finally {
            $this->finishSpan($span, 'delete', $id, $start);
        }
    }

    private function startSpan(string $name, PersistenceId $id): ?Span
    {
        try {
            return $this->observability->tracer()->startSpan(
                $name,
                SpanKind::Internal,
                [
                    'nexus.persistence.entity.type' => $id->entityType,
                    'nexus.persistence.id' => $id->toString(),
                ],
            );
        } catch (Throwable) {
            return null;
        }
    }

    private function finishSpan(?Span $span, string $operation, PersistenceId $id, int $startNanos): void
    {
        $this->safely(static fn (): mixed => $span?->end());
        $this->safely(function () use ($operation, $id, $startNanos): void {
            $this->operationDurationHistogram()->record(
                (hrtime(true) - $startNanos) / 1_000_000_000,
                ['nexus.persistence.entity.type' => $id->entityType, 'operation' => $operation],
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

    private function operationDurationHistogram(): Histogram
    {
        return $this->operationDuration ??= $this->observability->meter()->histogram(
            'nexus.persistence.operation.duration',
            's',
            'Duration of persistence store operations',
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
            // Telemetry must never break persistence.
        }
    }
}
