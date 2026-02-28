<?php

declare(strict_types=1);

namespace Monadial\Nexus\WorkerPool\Swoole\Transport;

use Closure;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\WorkerPool\Transport\WorkerTransport;
use Override;
use Swoole\Coroutine;
use Swoole\Thread\Queue;

/**
 * @psalm-api
 *
 * Thread-safe transport backed by Swoole\Thread\Queue.
 *
 * send()  — pushes Envelope to target worker's Queue. Thread\Queue serializes
 *           the object internally via php_serialize; no explicit serializer needed.
 *
 * listen() — registers a handler and spawns an adaptive-poll coroutine that
 *            reads from this worker's own Queue. Thread\Queue::pop() blocks the
 *            entire OS thread, so pop(0) (non-blocking) is used with Coroutine::sleep()
 *            backoff to remain coroutine-friendly.
 *
 * Adaptive poll backoff:
 *   - Messages present   → Coroutine::sleep(0) — immediate yield, no delay
 *   - 10 empty polls     → 100µs sleep
 *   - 100 empty polls    → 1ms sleep
 *   - 1000 empty polls   → 10ms sleep (idle steady state)
 *   - Message arrives    → reset counter, return to tight spin
 */
final class ThreadQueueTransport implements WorkerTransport
{
    /** @var ?Closure(Envelope): void */
    private ?Closure $listener = null;

    private bool $closed = false;

    /**
     * @param array<int, Queue> $queues All workers' queues, keyed by worker ID
     */
    public function __construct(private readonly array $queues, private readonly int $workerId) {}

    #[Override]
    public function send(int $targetWorker, Envelope $envelope): void
    {
        $queue = $this->queues[$targetWorker] ?? null;

        if ($queue === null) {
            return;
        }

        $queue->push($envelope, Queue::NOTIFY_ONE);
    }

    #[Override]
    public function listen(callable $onEnvelope): void
    {
        $this->listener = $onEnvelope(...);
        $this->startReceiveLoop();
    }

    #[Override]
    public function close(): void
    {
        $this->closed = true;
        $this->listener = null;
    }

    private function startReceiveLoop(): void
    {
        $queue = $this->queues[$this->workerId] ?? null;

        if ($queue === null) {
            return;
        }

        Coroutine::create(function () use ($queue): void {
            $emptyCount = 0;

            while (!$this->closed) {
                /** @var Envelope|null $envelope */
                $envelope = $queue->pop(0);

                if ($envelope !== null) {
                    $emptyCount = 0;

                    if ($this->listener !== null) {
                        ($this->listener)($envelope);
                    }

                    continue;
                }

                $emptyCount++;

                $sleepSeconds = match (true) {
                    $emptyCount < 10 => 0.0,
                    $emptyCount < 100 => 0.0001,
                    $emptyCount < 1000 => 0.001,
                    default => 0.01,
                };

                if ($sleepSeconds > 0.0) {
                    Coroutine::sleep($sleepSeconds);
                } else {
                    Coroutine::sleep(0);
                }
            }
        });
    }
}
