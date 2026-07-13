<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel\Export;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\BackpressureCapable;
use Monadial\Nexus\Observability\Metric\Meter;
use Monadial\Nexus\Runtime\Mailbox\EnqueueResult;

use function array_shift;
use function count;

/**
 * Shared Buffering -> Live -> Direct state machine for the actor-forwarding exporters.
 *
 * Buffering: batches accumulate in a bounded ring (oldest dropped on overflow) until
 * attach() is called, which drains the buffer to the actor ref in order and flips to Live.
 * Live: batches are handed to the ref (offer() when BackpressureCapable, else tell()).
 * Direct: entered permanently once the ref is found dead — including at attach() time,
 * if the ref is already dead, which flushes the buffer through the inner exporter
 * synchronously instead of going Live; batches are then delegated to the inner exporter
 * synchronously.
 */
trait ForwardsBatchesToActor
{
    private const int BUFFER_LIMIT = 64;

    /** @var list<object> */
    private array $buffer = [];

    private ?ActorRef $ref = null;

    private bool $direct = false;

    /**
     * @param array<array-key, mixed> $batch
     */
    abstract private function exportDirect(array $batch): void;

    /**
     * @param array<array-key, mixed> $batch
     */
    abstract private function wrap(array $batch): object;

    abstract private function signalName(): string;

    abstract private function meter(): Meter;

    public function attach(ActorRef $ref): void
    {
        if (!$ref->isAlive()) {
            $this->direct = true;

            foreach ($this->buffer as $batch) {
                $this->exportDirect($batch->batch);
            }

            $this->buffer = [];

            return;
        }

        $this->ref = $ref;

        foreach ($this->buffer as $batch) {
            $this->deliver($ref, $batch);
        }

        $this->buffer = [];
    }

    /**
     * @param array<array-key, mixed> $batch
     */
    private function forward(array $batch): void
    {
        if ($this->direct) {
            $this->exportDirect($batch);

            return;
        }

        if ($this->ref === null) {
            $this->bufferBatch($batch);

            return;
        }

        if (!$this->ref->isAlive()) {
            $this->direct = true;
            $this->exportDirect($batch);

            return;
        }

        $this->deliver($this->ref, $this->wrap($batch));
    }

    /**
     * @param array<array-key, mixed> $batch
     */
    private function bufferBatch(array $batch): void
    {
        if (count($this->buffer) >= self::BUFFER_LIMIT) {
            array_shift($this->buffer);
            $this->meter()->counter('nexus.observability.export.dropped')->add(1.0, [
                'reason' => 'buffer_full',
                'signal' => $this->signalName(),
            ]);
        }

        $this->buffer[] = $this->wrap($batch);
    }

    private function deliver(ActorRef $ref, object $message): void
    {
        if ($ref instanceof BackpressureCapable) {
            $result = $ref->offer($message);

            if ($result !== EnqueueResult::Accepted) {
                $this->meter()->counter('nexus.observability.export.dropped')->add(1.0, [
                    'reason' => 'mailbox_full',
                    'signal' => $this->signalName(),
                ]);
            }

            return;
        }

        $ref->tell($message);
    }

    private function isBuffering(): bool
    {
        return $this->ref === null && !$this->direct;
    }

    private function isLive(): bool
    {
        return $this->ref !== null && !$this->direct;
    }

    /**
     * Drains any buffered batches through a synchronous callback (the inner exporter's
     * own export path). Used by shutdown() while still Buffering, since there is no ref
     * to hand batches to and the SDK is asking us to flush everything now.
     *
     * @param callable(list<object>): void $flushThroughInner
     */
    private function flushBufferSynchronously(callable $flushThroughInner): void
    {
        $flushThroughInner($this->buffer);
        $this->buffer = [];
    }

    /**
     * Best-effort FlushNow tell while Live — the actor decides how to flush its own
     * pending batches; we do not wait for it.
     */
    private function tellFlushNow(): void
    {
        $this->ref?->tell(new FlushNow());
    }
}
