<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\SwooleThread\Transport;

use Monadial\Nexus\Cluster\Transport\EnvelopeTransport;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Override;
use RuntimeException;
use Swoole\Coroutine;
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
     * Receive the next envelope using adaptive polling.
     *
     * Uses non-blocking pop(0) in a loop with Coroutine::sleep()
     * to yield to other coroutines. Backoff adapts to load:
     * - Under load: tight loop with coroutine yield (~0 latency)
     * - Light load: 100us-1ms sleep
     * - Idle: 10ms sleep
     *
     * @throws RuntimeException If transport is closed with no pending envelopes
     *
     * @psalm-suppress MixedMethodCall, MixedAssignment
     */
    #[Override]
    public function receive(): Envelope
    {
        $emptyCount = 0;
        $queue = $this->queues[$this->workerId];

        while (!$this->closed) {
            /** @var Envelope|null $envelope */
            $envelope = $queue->pop(0);

            if ($envelope !== null) {
                return $envelope;
            }

            $emptyCount++;

            $sleep = match (true) {
                $emptyCount < 10 => 0.0,
                $emptyCount < 100 => 0.0001,
                $emptyCount < 1000 => 0.001,
                default => 0.01,
            };

            if ($sleep > 0.0) {
                Coroutine::sleep($sleep);
            } else {
                Coroutine::sleep(0);
            }
        }

        throw new RuntimeException('ThreadQueueTransport is closed');
    }

    /**
     * @psalm-suppress MixedMethodCall
     */
    #[Override]
    public function close(): void
    {
        $this->closed = true;
        $this->queues[$this->workerId]->clean();
    }
}
