<?php

declare(strict_types=1);

namespace Monadial\Nexus\WorkerPool\Swoole\Tests\Unit\Transport;

use Monadial\Nexus\WorkerPool\Swoole\Transport\ThreadQueueTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Thread\Queue;

#[CoversClass(ThreadQueueTransport::class)]
final class ThreadQueueTransportStopTest extends TestCase
{
    #[Test]
    public function stopSetsIsStoppedFlag(): void
    {
        $transport = $this->makeTransport();
        self::assertFalse($transport->isStopped());

        $transport->stop();

        self::assertTrue($transport->isStopped());
    }

    #[Test]
    public function stopIsIdempotent(): void
    {
        $transport = $this->makeTransport();
        $transport->stop();
        $transport->stop();

        self::assertTrue($transport->isStopped());
    }

    #[Test]
    public function receiveLoopExitsAfterStop(): void
    {
        // Coroutine\run() blocks until ALL coroutines spawned inside it have
        // finished. The receive loop is spawned as an internal coroutine by
        // listen(). If stop() does not cause the loop to exit, Coroutine\run()
        // would block forever (or until the OS kills it). A clean return
        // within 500ms proves the loop exited.
        $startedAt = microtime(true);

        Coroutine\run(function (): void {
            $transport = $this->makeTransport();

            // Start the receive loop (listen() spawns an internal coroutine
            // and returns immediately; the loop runs in the background).
            $transport->listen(static fn() => null);

            // Let the loop settle for one backoff cycle.
            Coroutine::sleep(0.02);

            // Signal stop — loop must exit on next wakeup (~10ms max).
            $transport->stop();

            // Yield to allow the loop coroutine to observe the flag and exit.
            Coroutine::sleep(0.05);
        });

        $elapsed = microtime(true) - $startedAt;

        // Coroutine\run() returned — the loop exited. Sanity-check the elapsed
        // time is well under 500ms so we are not just getting lucky on a slow teardown.
        self::assertLessThan(0.5, $elapsed, 'receive loop did not exit within 500ms after stop()');
    }

    private function makeTransport(): ThreadQueueTransport
    {
        return new ThreadQueueTransport([0 => new Queue()], workerId: 0);
    }
}
