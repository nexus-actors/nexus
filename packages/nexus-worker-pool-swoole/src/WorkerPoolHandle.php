<?php

declare(strict_types=1);

namespace Monadial\Nexus\WorkerPool\Swoole;

use Swoole\Thread\Atomic;
use Swoole\Thread\Map;
use Swoole\Thread\Queue;

/**
 * @psalm-api
 *
 * Handle passed to the onStart callback. Gives the main thread access to the
 * running worker pool for message injection, monitoring, and graceful stop.
 */
final readonly class WorkerPoolHandle
{
    /**
     * @param array<int, Queue> $queues
     */
    public function __construct(
        private int $workerCount,
        private array $queues,
        private Map $directory,
        private Atomic $stopSignal,
    ) {}

    public function workerCount(): int
    {
        return $this->workerCount;
    }

    /**
     * @return array<int, Queue>
     */
    public function queues(): array
    {
        return $this->queues;
    }

    public function directory(): Map
    {
        return $this->directory;
    }

    public function stop(): void
    {
        $this->stopSignal->set(1);
    }
}
