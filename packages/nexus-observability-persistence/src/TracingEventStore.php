<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Persistence;

use Monadial\Nexus\Observability\Metric\Counter;
use Monadial\Nexus\Observability\Metric\Histogram;
use Monadial\Nexus\Observability\Observability;
use Monadial\Nexus\Observability\Trace\Span;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Observability\Trace\StatusCode;
use Monadial\Nexus\Persistence\Event\EventEnvelope;
use Monadial\Nexus\Persistence\Event\EventStore;
use Monadial\Nexus\Persistence\PersistenceId;
use Override;
use Throwable;

use function count;
use function hrtime;

/**
 * @psalm-api
 *
 * Tracing decorator for an {@see EventStore}. Adds an Internal span and metrics
 * per operation. Store errors propagate (recorded on the span first); telemetry
 * errors never break the operation. Delegates directly when observability is
 * disabled.
 */
final class TracingEventStore implements EventStore
{
    private ?Counter $eventsPersisted = null;

    private ?Histogram $operationDuration = null;

    public function __construct(
        private readonly EventStore $inner,
        private readonly Observability $observability,
    ) {}

    #[Override]
    public function persist(PersistenceId $id, EventEnvelope ...$events): void
    {
        if (!$this->observability->isEnabled()) {
            $this->inner->persist($id, ...$events);

            return;
        }

        $span = $this->startSpan('EventStore.persist', $id, ['nexus.persistence.event.count' => count($events)]);
        $start = hrtime(true);

        try {
            $this->inner->persist($id, ...$events);
            $this->safely(fn (): mixed => $this->eventsPersistedCounter()->add(count($events), ['nexus.persistence.entity.type' => $id->entityType]));
        } catch (Throwable $e) {
            $this->recordError($span, $e);

            throw $e;
        } finally {
            $this->finishSpan($span, 'persist', $id, $start);
        }
    }

    #[Override]
    public function load(PersistenceId $id, int $fromSequenceNr = 0, int $toSequenceNr = PHP_INT_MAX): iterable
    {
        if (!$this->observability->isEnabled()) {
            return $this->inner->load($id, $fromSequenceNr, $toSequenceNr);
        }

        $span = $this->startSpan('EventStore.load', $id, ['nexus.persistence.from_sequence_nr' => $fromSequenceNr]);
        $start = hrtime(true);

        try {
            return $this->inner->load($id, $fromSequenceNr, $toSequenceNr);
        } catch (Throwable $e) {
            $this->recordError($span, $e);

            throw $e;
        } finally {
            $this->finishSpan($span, 'load', $id, $start);
        }
    }

    #[Override]
    public function deleteUpTo(PersistenceId $id, int $toSequenceNr): void
    {
        if (!$this->observability->isEnabled()) {
            $this->inner->deleteUpTo($id, $toSequenceNr);

            return;
        }

        $span = $this->startSpan('EventStore.deleteUpTo', $id, ['nexus.persistence.to_sequence_nr' => $toSequenceNr]);
        $start = hrtime(true);

        try {
            $this->inner->deleteUpTo($id, $toSequenceNr);
        } catch (Throwable $e) {
            $this->recordError($span, $e);

            throw $e;
        } finally {
            $this->finishSpan($span, 'deleteUpTo', $id, $start);
        }
    }

    #[Override]
    public function highestSequenceNr(PersistenceId $id): int
    {
        if (!$this->observability->isEnabled()) {
            return $this->inner->highestSequenceNr($id);
        }

        $span = $this->startSpan('EventStore.highestSequenceNr', $id, []);
        $start = hrtime(true);

        try {
            return $this->inner->highestSequenceNr($id);
        } catch (Throwable $e) {
            $this->recordError($span, $e);

            throw $e;
        } finally {
            $this->finishSpan($span, 'highestSequenceNr', $id, $start);
        }
    }

    /**
     * @param array<string, scalar> $extra
     */
    private function startSpan(string $name, PersistenceId $id, array $extra): ?Span
    {
        try {
            $attributes = [
                'nexus.persistence.entity.type' => $id->entityType,
                'nexus.persistence.id' => $id->toString(),
            ];

            foreach ($extra as $key => $value) {
                $attributes[$key] = $value;
            }

            return $this->observability->tracer()->startSpan(
                $name,
                SpanKind::Internal,
                $attributes,
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

    private function eventsPersistedCounter(): Counter
    {
        return $this->eventsPersisted ??= $this->observability->meter()->counter(
            'nexus.persistence.events.persisted',
            '{event}',
            'Events persisted to the event store',
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
