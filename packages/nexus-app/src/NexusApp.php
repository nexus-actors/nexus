<?php

declare(strict_types=1);

namespace Monadial\Nexus\App;

use Closure;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Observability\NoopObservability;
use Monadial\Nexus\Observability\Observability;
use Monadial\Nexus\Runtime\Runtime\Runtime;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fluent bootstrap entry point for Nexus actor applications.
 *
 * `NexusApp` is the top-level application kernel. It collects actor definitions
 * and optional lifecycle callbacks, then spawns them all and starts the runtime
 * event loop with a single `run()` call. For more control — for example, to wire
 * OS signal handling before the loop starts — use `start()` instead, which returns
 * the configured {@see ActorSystem} without blocking.
 *
 * All actor registration methods return `$this`, enabling a fluent builder style:
 * ```php
 * NexusApp::create('shop')
 *     ->actor('orders', Props::fromBehavior($orderBehavior))
 *     ->actor('payments', Props::fromFactory(fn() => new PaymentActor()))
 *     ->onStart(function (StartedApp $app): void {
 *         // retrieve a typed handle to a root actor spawned above
 *         $app->ref('orders')->tell(new WarmUp());
 *     })
 *     ->run(new FiberRuntime());
 * ```
 *
 * @see ActorSystem  Underlying system created by start() / run()
 * @see Props        Actor spawn configuration passed to actor()
 * @see Runtime      Concurrency backend (FiberRuntime, SwooleRuntime, StepRuntime)
 *
 * @psalm-api
 */
final class NexusApp
{
    /** @var list<ActorDefinition<object>> */
    private array $definitions = [];

    private ?Observability $observability = null;

    /** @var ?Closure(StartedApp): void */
    private ?Closure $startCallback = null;

    private function __construct(private readonly string $appName) {}

    /**
     * Create a new NexusApp with the given application name.
     *
     * The name is passed through to {@see \Monadial\Nexus\Core\Actor\ActorSystem::create()}
     * and appears in log output and actor paths.
     *
     * @param string $name Human-readable application name; becomes the root actor system name
     * @return self Fresh builder ready for actor registration
     */
    public static function create(string $name): self
    {
        return new self($name);
    }

    /**
     * Returns the application name supplied to {@see create()}.
     *
     * @return string The application name passed to the constructor
     */
    public function name(): string
    {
        return $this->appName;
    }

    /**
     * Register an actor to be spawned on startup.
     *
     * Definitions are accumulated in registration order and spawned during
     * {@see start()} before the optional start callback fires. Duplicate names
     * are not detected here — the conflict surfaces when
     * {@see \Monadial\Nexus\Core\Actor\ActorSystem::spawn()} throws
     * {@see \Monadial\Nexus\Core\Exception\ActorNameExistsException}.
     *
     * @template T of object
     * @param string   $name  Unique child name under the user guardian (becomes part of the actor path)
     * @param Props<T> $props Spawn configuration describing the behavior, mailbox, and supervision
     * @return self This builder, for fluent chaining
     */
    public function actor(string $name, Props $props): self
    {
        $this->definitions[] = new ActorDefinition($name, $props);

        return $this;
    }

    /**
     * Register a callback invoked after all actors are spawned.
     *
     * The callback fires synchronously at the end of {@see start()}, before the
     * runtime event loop is started, with the {@see StartedApp} as its sole
     * argument. Use it to retrieve typed handles to the spawned root actors
     * (`$app->ref('orders')`), send warm-up messages, or wire external listeners.
     * Replacing a previously registered callback is allowed; only the most recent
     * one is invoked. Reach the underlying system via `$app->system()`.
     *
     * @param callable(StartedApp): void $callback Invoked once with the StartedApp after spawn
     * @return self This builder, for fluent chaining
     */
    public function onStart(callable $callback): self
    {
        $this->startCallback = $callback(...);

        return $this;
    }

    /**
     * Attach an observability provider (traces + metrics). The provider is
     * threaded into the actor system and shut down (flushed) when {@see run()}
     * returns. Build it via ObservabilityFactory::fromConfig(...) or pass a
     * NoopObservability to disable. Optional — defaults to no-op.
     */
    public function withObservability(Observability $observability): self
    {
        $this->observability = $observability;

        return $this;
    }

    /**
     * Returns all registered actor definitions.
     *
     * @return list<ActorDefinition<object>> Definitions in registration order
     */
    public function actors(): array
    {
        return $this->definitions;
    }

    /**
     * Spawn all registered actors, invoke the start callback, and return the
     * live system without starting the runtime event loop.
     *
     * Callers use this when they need to wire infrastructure — OS signal
     * handlers, HTTP servers, metric scrapers — around the actor system before
     * blocking on {@see \Monadial\Nexus\Core\Actor\ActorSystem::run()}. For the
     * common case where no extra setup is needed, prefer {@see run()}.
     *
     * @param Runtime              $runtime Concurrency backend (Fiber, Swoole, Step)
     * @param LoggerInterface|null $logger  Optional PSR-3 logger; defaults to the ActorSystem default when null
     * @return StartedApp The started app: the live system plus the named root actor handles, ready for `run()`
     */
    public function start(Runtime $runtime, ?LoggerInterface $logger = null): StartedApp
    {
        $system = ActorSystem::create(
            $this->appName,
            $runtime,
            logger: $logger,
            observability: $this->observability ?? new NoopObservability(),
        );

        $refs = [];

        // Spawn in registration order so a later root can be wired to an earlier
        // one via the returned handles.
        foreach ($this->definitions as $definition) {
            $refs[$definition->name] = $system->spawn($definition->props, $definition->name);
        }

        $started = new StartedApp($system, $refs);

        if ($this->startCallback !== null) {
            ($this->startCallback)($started);
        }

        return $started;
    }

    /**
     * Run in single-process mode with the given runtime.
     *
     * Convenience wrapper that calls {@see start()} and then blocks on
     * {@see StartedApp::run()} until the system is shut down. Suitable for Hello
     * World and CLI entry points; long-running services that need to react to OS
     * signals should call {@see start()} and drive the loop explicitly via the
     * returned {@see StartedApp}.
     *
     * @param Runtime              $runtime Concurrency backend (Fiber, Swoole, Step)
     * @param LoggerInterface|null $logger  Optional PSR-3 logger; defaults to the ActorSystem default when null
     */
    public function run(Runtime $runtime, ?LoggerInterface $logger = null): void
    {
        $observability = $this->observability;

        try {
            $this->start($runtime, $logger)->run();
        } finally {
            try {
                $observability?->shutdown();
            } catch (Throwable) {
                // Telemetry flush must not mask an application error.
            }
        }
    }
}
