<?php
declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Swoole;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\Cancellable;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Core\Mailbox\Mailbox;
use Monadial\Nexus\Core\Mailbox\MailboxConfig;
use Monadial\Nexus\Core\Runtime\Runtime;
use Override;
use Swoole\Coroutine;

use function Swoole\Coroutine\run;

use Swoole\Timer;

/**
 * Swoole coroutine-based runtime.
 *
 * Actors run as Swoole coroutines inside Co\run(). Scheduling uses Swoole\Timer.
 * spawn() and scheduleOnce/scheduleRepeatedly may be called before run() —
 * callables are queued and executed when Co\run() starts.
 *
 * @psalm-api
 */
final class SwooleRuntime implements Runtime
{
    private bool $running = false;

    private bool $insideCoRun = false;

    private int $nextId = 0;

    /** @var array<string, callable> */
    private array $pendingSpawns = [];

    /** @var list<callable(): void> */
    private array $pendingTimers = [];

    /** @var array<int, true> */
    private array $timerIds = [];

    public function __construct(private readonly SwooleConfig $config = new SwooleConfig())
    {
    }

    #[Override]
    public function name(): string
    {
        return 'swoole';
    }

    #[Override]
    public function createMailbox(MailboxConfig $config): Mailbox
    {
        return new SwooleMailbox($config, ActorPath::root());
    }

    #[Override]
    public function spawn(callable $actorLoop): string
    {
        $id = 'swoole-' . $this->nextId++;

        if ($this->insideCoRun) {
            Coroutine::create($actorLoop);
        } else {
            $this->pendingSpawns[$id] = $actorLoop;
        }

        return $id;
    }

    #[Override]
    public function scheduleOnce(Duration $delay, callable $callback): Cancellable
    {
        if ($this->insideCoRun) {
            return $this->createOnceTimer($delay, $callback);
        }

        // Before run() — queue for later, return a deferred cancellable
        $cancelled = false;

        $this->pendingTimers[] = function () use ($delay, $callback, &$cancelled): void {
            /** @psalm-suppress RedundantCondition -- $cancelled is mutated via reference by DeferredCancellable */
            if (!$cancelled) {
                $this->createOnceTimer($delay, $callback);
            }
        };

        return new DeferredCancellable($cancelled);
    }

    #[Override]
    public function scheduleRepeatedly(Duration $initialDelay, Duration $interval, callable $callback): Cancellable
    {
        if ($this->insideCoRun) {
            return $this->createRepeatingTimer($initialDelay, $interval, $callback);
        }

        // Before run() — queue for later
        $cancelled = false;

        $this->pendingTimers[] = function () use ($initialDelay, $interval, $callback, &$cancelled): void {
            /** @psalm-suppress RedundantCondition -- $cancelled is mutated via reference by DeferredCancellable */
            if (!$cancelled) {
                $this->createRepeatingTimer($initialDelay, $interval, $callback);
            }
        };

        return new DeferredCancellable($cancelled);
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

    #[Override]
    public function run(): void
    {
        $this->running = true;

        if ($this->config->enableCoroutineHook) {
            Coroutine::set(['hook_flags' => SWOOLE_HOOK_ALL]);
        }

        /** @psalm-suppress UnusedFunctionCall -- Co\run() return value is not needed */
        run(function (): void {
            $this->insideCoRun = true;

            // Execute all pending timers
            foreach ($this->pendingTimers as $action) {
                $action();
            }

            $this->pendingTimers = [];

            // Execute all pending spawns
            foreach ($this->pendingSpawns as $loop) {
                Coroutine::create($loop);
            }

            $this->pendingSpawns = [];

            // Co\run blocks until all coroutines and timers complete
        });

        $this->insideCoRun = false;
        $this->running = false;
    }

    #[Override]
    public function shutdown(Duration $timeout): void
    {
        // Clear all tracked timers so Co\run() can exit
        foreach ($this->timerIds as $id => $_) {
            Timer::clear($id);
        }

        $this->timerIds = [];
    }

    #[Override]
    public function isRunning(): bool
    {
        return $this->running;
    }

    private function createOnceTimer(Duration $delay, callable $callback): SwooleCancellable
    {
        $ms = max(1, $delay->toMillis());

        /** @var int $timerId Swoole Timer::after returns int timer ID */
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

        // Use Timer::after for initial delay, then Timer::tick for repeating
        /** @var int $timerId Swoole Timer::after returns int timer ID */
        $timerId = Timer::after($initialMs, function () use ($intervalMs, $callback): void {
            // Fire first invocation
            ($callback)();

            // Start repeating
            /** @var int $tickId Swoole Timer::tick returns int timer ID */
            $tickId = Timer::tick($intervalMs, static function () use ($callback): void {
                ($callback)();
            });

            $this->timerIds[$tickId] = true;
        });

        $this->timerIds[$timerId] = true;

        return new SwooleCancellable($timerId);
    }
}
