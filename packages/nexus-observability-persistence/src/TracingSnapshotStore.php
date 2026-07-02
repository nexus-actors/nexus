<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Persistence;

use Monadial\Nexus\Observability\Metric\Counter;
use Monadial\Nexus\Observability\Metric\Histogram;
use Monadial\Nexus\Observability\Observability;
use Monadial\Nexus\Observability\Trace\Span;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Observability\Trace\StatusCode;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\Snapshot\SnapshotEnvelope;
use Monadial\Nexus\Persistence\Snapshot\SnapshotStore;
use Override;
use Throwable;

use function hrtime;

/**
 * @psalm-api
 *
 * Tracing decorator for a {@see SnapshotStore}. Store errors propagate;
 * telemetry never breaks the operation; delegates when disabled.
 */
final class TracingSnapshotStore implements SnapshotStore
{
    private ?Counter $snapshotsSaved = null;

    private ?Histogram $operationDuration = null;

    public function __construct(
        private readonly SnapshotStore $inner,
        private readonly Observability $observability,
    ) {}

    #[Override]
    public function save(PersistenceId $id, SnapshotEnvelope $snapshot): void
    {
        if (!$this->observability->isEnabled()) {
            $this->inner->save($id, $snapshot);

            return;
        }

        $span = $this->startSpan('SnapshotStore.save', $id);
        $start = hrtime(true);

        try {
            $this->inner->save($id, $snapshot);
            $this->safely(fn (): mixed => $this->snapshotsSavedCounter()->add(1, ['nexus.persistence.entity.type' => $id->entityType]));
        } catch (Throwable $e) {
            $this->recordError($span, $e);

            throw $e;
        } finally {
            $this->finishSpan($span, 'save', $id, $start);
        }
    }

    #[Override]
    public function load(PersistenceId $id): ?SnapshotEnvelope
    {
        if (!$this->observability->isEnabled()) {
            return $this->inner->load($id);
        }

        $span = $this->startSpan('SnapshotStore.load', $id);
        $start = hrtime(true);

        try {
            return $this->inner->load($id);
        } catch (Throwable $e) {
            $this->recordError($span, $e);

            throw $e;
        } finally {
            $this->finishSpan($span, 'load', $id, $start);
        }
    }

    #[Override]
    public function delete(PersistenceId $id, int $maxSequenceNr): void
    {
        if (!$this->observability->isEnabled()) {
            $this->inner->delete($id, $maxSequenceNr);

            return;
        }

        $span = $this->startSpan('SnapshotStore.delete', $id);
        $start = hrtime(true);

        try {
            $this->inner->delete($id, $maxSequenceNr);
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

    private function snapshotsSavedCounter(): Counter
    {
        return $this->snapshotsSaved ??= $this->observability->meter()->counter(
            'nexus.persistence.snapshots.saved',
            '{snapshot}',
            'Snapshots written to the snapshot store',
        );
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
