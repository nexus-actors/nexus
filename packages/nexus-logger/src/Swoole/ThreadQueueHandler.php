<?php

declare(strict_types=1);

namespace Monadial\Nexus\Logger\Swoole;

use Monadial\Nexus\Logger\Formatter;
use Monadial\Nexus\Logger\Handler;
use Monadial\Nexus\Logger\Record;
use Override;
use Swoole\Thread\Queue;

/**
 * @psalm-api
 *
 * Producer-side Handler that formats a Record into a string and pushes it
 * onto a shared Swoole\Thread\Queue. A separate writer thread drains the
 * queue and performs the actual file/stream I/O.
 *
 * Benefits over FileHandler:
 * - No per-write flock(LOCK_EX) contention (only one process opens the file).
 * - No per-write open()/close() syscalls (writer keeps the fd hot).
 * - Naturally batches under load — writer processes pushes as fast as it can.
 *
 * The queue is unbounded for the prototype; production would add a max
 * length + drop-on-overflow policy.
 */
final readonly class ThreadQueueHandler implements Handler
{
    public function __construct(private Queue $queue, private Formatter $formatter) {}

    #[Override]
    public function handle(Record $record): void
    {
        /** @psalm-suppress InvalidArgument — Thread\Queue::push accepts mixed at runtime. */
        $this->queue->push($this->formatter->format($record));
    }
}
