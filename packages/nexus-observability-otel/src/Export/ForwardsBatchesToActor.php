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
 *
 * @template TItem the SDK batch item type (span data, metric, or log record)
 */
trait ForwardsBatchesToActor
{
    private const int BUFFER_LIMIT = 64;

    /** @var list<list<TItem>> */
    private array $buffer = [];

    /** @var ActorRef<ExportCommand>|null */
    private ?ActorRef $ref = null;

    private bool $direct = false;

    /**
     * @param list<TItem> $batch
     */
    abstract private function exportDirect(array $batch): void;

    /**
     * @param list<TItem> $batch
     */
    abstract private function wrap(array $batch): ExportCommand;

    abstract private function signalName(): string;

    abstract private function meter(): Meter;

    /**
     * @param ActorRef<ExportCommand> $ref
     */
    public function attach(ActorRef $ref): void
    {
        if (!$ref->isAlive()) {
            $this->direct = true;

            foreach ($this->buffer as $batch) {
                $this->exportDirect($batch);
            }

            $this->buffer = [];

            return;
        }

        $this->ref = $ref;
        // Restore Live: supports recovery when a died export actor is re-spawned and re-attached.
        $this->direct = false;

        foreach ($this->buffer as $batch) {
            $this->deliver($ref, $this->wrap($batch));
        }

        $this->buffer = [];
    }

    /**
     * @param list<TItem> $batch
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
     * @param list<TItem> $batch
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

        $this->buffer[] = $batch;
    }

    /**
     * @param ActorRef<ExportCommand> $ref
     */
    private function deliver(ActorRef $ref, ExportCommand $message): void
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
     * Hands out the buffered batches and empties the buffer. Used by shutdown() while
     * still Buffering, since there is no ref to hand batches to and the SDK is asking
     * us to flush everything now — the caller drains them through the inner exporter's
     * own synchronous export path.
     *
     * @return list<list<TItem>>
     */
    private function takeBuffer(): array
    {
        $buffered = $this->buffer;
        $this->buffer = [];

        return $buffered;
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
