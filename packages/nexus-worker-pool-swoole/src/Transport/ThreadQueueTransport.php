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
 * Adaptive poll backoff (Swoole minimum sleep is 1 ms):
 *   - Messages present   → continue immediately (no sleep, tight drain loop)
 *   - 1–9 empty polls    → 1ms sleep  (recently active, check again soon)
 *   - 10–99 empty polls  → 1ms sleep
 *   - 100–999 empty polls→ 5ms sleep
 *   - 1000+ empty polls  → 10ms sleep (idle steady state)
 *   - Message arrives    → reset counter, return to tight drain loop
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

                Coroutine::sleep(match (true) {
                    $emptyCount < 10 => 0.001,
                    $emptyCount < 100 => 0.001,
                    $emptyCount < 1000 => 0.005,
                    default => 0.01,
                });
            }
        });
    }
}
