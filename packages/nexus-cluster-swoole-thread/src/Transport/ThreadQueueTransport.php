<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\SwooleThread\Transport;

use Monadial\Nexus\Cluster\Transport\EnvelopeTransport;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Override;
use RuntimeException;
use Swoole\Coroutine;
use Swoole\Event;
use Swoole\Thread\Queue;

/**
 * @psalm-api
 * @psalm-suppress UndefinedDocblockClass, UndefinedClass
 *
 * Thread\Queue-backed envelope transport.
 * Each worker owns one Queue as its inbox. Sender pushes to target worker's queue.
 * Receive uses non-blocking pop(0) with adaptive polling to avoid blocking the
 * coroutine event loop (Thread\Queue::pop blocks the OS thread, not the coroutine).
 *
 * Swoole Thread classes require PHP ZTS and are not covered by swoole/ide-helper stubs.
 */
final class ThreadQueueTransport implements EnvelopeTransport
{
    private const int BATCH_SIZE = 128;

    private const int SPIN_COUNT = 8;

    private const int IDLE_THRESHOLD = 1000;

    /** @var list<Envelope> */
    private array $buffer = [];

    private int $bufferPos = 0;

    private int $bufferLen = 0;

    private bool $closed = false;

    /**
     * @param list<Queue> $queues One queue per worker (index = worker ID)
     * @param int $workerId This worker's ID (reads from queues[$workerId])
     */
    public function __construct(private readonly array $queues, private readonly int $workerId) {}

    /**
     * @psalm-suppress MixedMethodCall
     */
    #[Override]
    public function send(int $targetWorker, Envelope $envelope): void
    {
        if ($this->closed) {
            return;
        }

        $this->queues[$targetWorker]->push($envelope, 0);
    }

    /**
     * @psalm-suppress MixedMethodCall, MixedAssignment
     */
    #[Override]
    public function receive(): Envelope
    {
        if ($this->bufferPos < $this->bufferLen) {
            return $this->buffer[$this->bufferPos++];
        }

        return $this->awaitEnvelope();
    }

    /**
     * @psalm-suppress MixedMethodCall
     */
    #[Override]
    public function close(): void
    {
        $this->closed = true;
        $this->buffer = [];
        $this->bufferPos = 0;
        $this->bufferLen = 0;
        $this->queues[$this->workerId]->clean();
    }

    /**
     * Block until at least one envelope is available, then return the first.
     *
     * Three phases per iteration:
     * 1. Batch drain — pop up to BATCH_SIZE items to amortize method call overhead
     * 2. Spin-wait — SPIN_COUNT individual pops to catch inter-burst arrivals
     * 3. Adaptive yield — defer+yield (~0.86us) for active traffic, sleep(1ms) when idle
     *
     * @throws RuntimeException If transport is closed with no pending envelopes
     *
     * @psalm-suppress MixedMethodCall, MixedAssignment
     */
    private function awaitEnvelope(): Envelope
    {
        $queue = $this->queues[$this->workerId];
        $idlePolls = 0;

        while (!$this->closed) {
            // Phase 1: Batch drain
            $this->resetBuffer();
            $this->drainQueue($queue, self::BATCH_SIZE);

            if ($this->bufferLen > 0) {
                return $this->buffer[$this->bufferPos++];
            }

            // Phase 2: Spin-wait — tight individual pops before yielding
            for ($spin = 0; $spin < self::SPIN_COUNT; $spin++) {
                /** @var Envelope|null $envelope */
                $envelope = $queue->pop(0);

                if ($envelope !== null) {
                    $this->buffer[] = $envelope;
                    $this->bufferLen = 1;
                    $this->drainQueue($queue, self::BATCH_SIZE - 1);

                    return $this->buffer[$this->bufferPos++];
                }
            }

            // Phase 3: Adaptive yield
            $idlePolls++;

            if ($idlePolls < self::IDLE_THRESHOLD) {
                $this->yieldToEventLoop();
            } else {
                Coroutine::sleep(0.001);
                $idlePolls = 0;
            }
        }

        throw new RuntimeException('ThreadQueueTransport is closed');
    }

    /**
     * Pop up to $limit envelopes from the queue into the buffer.
     *
     * @psalm-suppress UndefinedClass, MixedMethodCall, MixedAssignment
     */
    private function drainQueue(Queue $queue, int $limit): void
    {
        for ($i = 0; $i < $limit; $i++) {
            /** @var Envelope|null $envelope */
            $envelope = $queue->pop(0);

            if ($envelope === null) {
                return;
            }

            $this->buffer[] = $envelope;
            $this->bufferLen++;
        }
    }

    /**
     * Yield to the Swoole event loop via defer+resume (~0.86us per context switch).
     *
     * @psalm-suppress MixedMethodCall, MixedArgument
     */
    private function yieldToEventLoop(): void
    {
        /** @var int $cid */
        $cid = Coroutine::getCid();
        Event::defer(static function () use ($cid): void {
            Coroutine::resume($cid);
        });
        Coroutine::yield();
    }

    private function resetBuffer(): void
    {
        $this->buffer = [];
        $this->bufferPos = 0;
        $this->bufferLen = 0;
    }
}
