<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Runtime;

use Monadial\Nexus\Runtime\Async\FutureSlot;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Mailbox\Mailbox;
use Monadial\Nexus\Runtime\Mailbox\MailboxConfig;

/**
 * Abstraction over the concurrency backend that powers the actor system.
 *
 * A `Runtime` provides the primitives — mailboxes, fiber/coroutine spawning, timers,
 * cooperative yielding, and the main event loop — that the `ActorSystem` and `ActorCell`
 * depend on. By programming to this interface, actor code is identical whether it runs
 * on PHP Fibers (development), Swoole coroutines (production), or the deterministic
 * `StepRuntime` (testing).
 *
 * Three implementations ship in the core distribution:
 * - `FiberRuntime` (nexus-runtime-fiber) — PHP Fiber-based, zero external dependencies.
 * - `SwooleRuntime` (nexus-runtime-swoole) — Swoole coroutines with true async I/O.
 * - `StepRuntime` (nexus-runtime-step) — Single-step, fully deterministic for tests.
 *
 * Example:
 * ```php
 * $runtime = new FiberRuntime();
 * $system  = ActorSystem::create('my-app', $runtime);
 * $ref     = $system->spawn(Props::fromBehavior($behavior), 'worker');
 * $ref->tell(new DoWork());
 * $system->run(); // blocks until shutdown
 * ```
 *
 * @see ActorSystem   for the top-level entry point that consumes a Runtime
 * @see MailboxConfig for configuring the mailboxes the runtime creates
 * @see Duration      for expressing delays and timeouts
 *
 * @psalm-api
 */
interface Runtime
{
    /**
     * Return the human-readable name that identifies this runtime implementation.
     */
    public function name(): string;

    /**
     * Create a new mailbox governed by `$config`.
     *
     * The returned mailbox is bound to this runtime's concurrency model — e.g. a
     * Fiber mailbox suspends fibers on `dequeueBlocking()`, while a Swoole mailbox
     * blocks a coroutine on a Swoole channel.
     *
     * @template T of object
     * @return Mailbox<T>
     */
    public function createMailbox(MailboxConfig $config): Mailbox;

    /**
     * Create a lightweight value slot for the ask pattern.
     * The caller is responsible for scheduling timeout failures.
     */
    public function createFutureSlot(): FutureSlot;

    /**
     * Spawn a new fiber or coroutine to run `$actorLoop`.
     *
     * The runtime starts the loop immediately (or schedules it for the next tick)
     * and returns an opaque identifier string for observability.
     */
    public function spawn(callable $actorLoop): string;

    /**
     * Schedule `$callback` to run once after `$delay` has elapsed.
     *
     * Returns a `Cancellable` that can be used to abort the pending callback before
     * it fires. The callback is invoked in the runtime's execution context.
     */
    public function scheduleOnce(Duration $delay, callable $callback): Cancellable;

    /**
     * Schedule `$callback` to run repeatedly, starting after `$initialDelay`.
     *
     * Subsequent invocations are spaced `$interval` apart. Returns a `Cancellable`
     * to stop future invocations.
     */
    public function scheduleRepeatedly(Duration $initialDelay, Duration $interval, callable $callback): Cancellable;

    /**
     * Cooperatively yield execution to allow other fibers or coroutines to run.
     *
     * Called inside actor message loops to prevent one busy actor from starving others.
     */
    public function yield(): void;

    /**
     * Suspend the current fiber or coroutine for `$duration`.
     *
     * Unlike `usleep()`, this does not block the OS thread — other actors continue
     * to process messages while this one sleeps.
     */
    public function sleep(Duration $duration): void;

    /**
     * Start the runtime event loop and block until all actors have shut down.
     *
     * For `FiberRuntime` this drives the fiber scheduler. For `SwooleRuntime` this
     * enters the Swoole event loop. Returns only after `shutdown()` completes.
     */
    public function run(): void;

    /**
     * Initiate a graceful shutdown and wait up to `$timeout` for completion.
     *
     * Signals all actors to stop, drains pending messages, and exits the event loop.
     */
    public function shutdown(Duration $timeout): void;

    /**
     * Return `true` if the runtime event loop is currently active.
     */
    public function isRunning(): bool;
}
