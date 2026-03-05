<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Swoole;

use Monadial\Nexus\Runtime\Async\FutureSlot;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Mailbox\Mailbox;
use Monadial\Nexus\Runtime\Mailbox\MailboxConfig;
use Monadial\Nexus\Runtime\Runtime\Cancellable;
use Monadial\Nexus\Runtime\Runtime\Runtime;
use Override;
use Swoole\Coroutine;
use Swoole\Timer;

/**
 * Swoole runtime for embedding within an existing Swoole coroutine event loop.
 *
 * Unlike SwooleRuntime (which calls Co\run()), this runtime assumes it is already
 * executing inside a Swoole worker event loop (e.g. Swoole\Http\Server onWorkerStart
 * or onRequest). spawn() calls Coroutine::create() directly. run() is a no-op.
 *
 * Use this inside Swoole HTTP server workers where Co\run() cannot be called.
 *
 * @psalm-api
 */
final class SwooleEmbeddedRuntime implements Runtime
{
    private bool $running = false;

    private int $nextId = 0;

    /** @var array<int, true> */
    private array $timerIds = [];

    #[Override]
    public function name(): string
    {
        return 'swoole-embedded';
    }

    /**
     * @template TM of object
     * @return Mailbox<TM>
     */
    #[Override]
    public function createMailbox(MailboxConfig $config): Mailbox
    {
        /** @var SwooleMailbox<TM> $mailbox */
        $mailbox = new SwooleMailbox($config);

        return $mailbox;
    }

    #[Override]
    public function createFutureSlot(): FutureSlot
    {
        return new SwooleFutureSlot();
    }

    #[Override]
    public function spawn(callable $actorLoop): string
    {
        Coroutine::create($actorLoop);

        return 'swoole-embedded-' . $this->nextId++;
    }

    #[Override]
    public function scheduleOnce(Duration $delay, callable $callback): Cancellable
    {
        return $this->createOnceTimer($delay, $callback);
    }

    #[Override]
    public function scheduleRepeatedly(Duration $initialDelay, Duration $interval, callable $callback): Cancellable
    {
        return $this->createRepeatingTimer($initialDelay, $interval, $callback);
    }

    #[Override]
    public function yield(): void
    {
        Coroutine::yield();
    }

    #[Override]
    public function sleep(Duration $duration): void
    {
        $seconds = $duration->toSecondsFloat();

        if ($seconds > 0) {
            Coroutine::sleep($seconds);
        }
    }

    /**
     * No-op — we are already inside an existing Swoole event loop.
     * Marks the runtime as running so ActorSystem behaves correctly.
     */
    #[Override]
    public function run(): void
    {
        $this->running = true;
    }

    /**
     * Clears all tracked timers.
     *
     * Unlike SwooleRuntime where $running is reset naturally when Co\run() returns,
     * the embedded runtime must explicitly reset it here since run() is a no-op.
     */
    #[Override]
    public function shutdown(Duration $timeout): void
    {
        foreach ($this->timerIds as $id => $_) {
            Timer::clear($id);
        }

        $this->timerIds = [];
        $this->running = false;
    }

    #[Override]
    public function isRunning(): bool
    {
        return $this->running;
    }

    private function createOnceTimer(Duration $delay, callable $callback): SwooleCancellable
    {
        $ms = max(1, $delay->toMillis());

        /** @var int $timerId */
        $timerId = Timer::after($ms, static function () use ($callback): void {
            ($callback)();
        });

        $this->timerIds[$timerId] = true;

        return new SwooleCancellable($timerId);
    }

    private function createRepeatingTimer(
        Duration $initialDelay,
        Duration $interval,
        callable $callback,
    ): SwooleCancellable {
        $intervalMs = max(1, $interval->toMillis());
        $initialMs = max(1, $initialDelay->toMillis());

        /** @var int $timerId */
        $timerId = Timer::after($initialMs, function () use ($intervalMs, $callback): void {
            ($callback)();

            /** @var int $tickId */
            $tickId = Timer::tick($intervalMs, static function () use ($callback): void {
                ($callback)();
            });

            $this->timerIds[$tickId] = true;
        });

        $this->timerIds[$timerId] = true;

        return new SwooleCancellable($timerId);
    }
}
