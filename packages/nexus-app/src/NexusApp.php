<?php

declare(strict_types=1);

namespace Monadial\Nexus\App;

use Closure;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Runtime\Runtime\Runtime;
use Psr\Log\LoggerInterface;

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
 *     ->onStart(function (ActorSystem $system): void {
 *         // send a warm-up message after all actors are spawned
 *         $system->deadLetters();
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

    /** @var ?Closure(ActorSystem): void */
    private ?Closure $startCallback = null;

    private function __construct(private readonly string $appName) {}

    /**
     * Create a new NexusApp with the given application name.
     *
     * The name is passed through to {@see ActorSystem::create()} and appears in
     * log output and actor paths.
     */
    public static function create(string $name): self
    {
        return new self($name);
    }

    /**
     * Returns the application name supplied to {@see create()}.
     */
    public function name(): string
    {
        return $this->appName;
    }

    /**
     * Register an actor to be spawned on startup.
     *
     * @template T of object
     * @param Props<T> $props
     */
    public function actor(string $name, Props $props): self
    {
        $this->definitions[] = new ActorDefinition($name, $props);

        return $this;
    }

    /**
     * Register a callback invoked after all actors are spawned.
     *
     * @param callable(ActorSystem): void $callback
     */
    public function onStart(callable $callback): self
    {
        $this->startCallback = $callback(...);

        return $this;
    }

    /**
     * Returns all registered actor definitions.
     *
     * @return list<ActorDefinition<object>>
     */
    public function actors(): array
    {
        return $this->definitions;
    }

    /**
     * Spawn all registered actors and invoke the start callback, but do not
     * start the runtime event loop. Returns the ActorSystem so the caller
     * can wire signal handling or other infrastructure before calling
     * {@see ActorSystem::run()}.
     */
    public function start(Runtime $runtime, ?LoggerInterface $logger = null): ActorSystem
    {
        $system = ActorSystem::create($this->appName, $runtime, logger: $logger);

        foreach ($this->definitions as $definition) {
            $system->spawn($definition->props, $definition->name);
        }

        if ($this->startCallback !== null) {
            ($this->startCallback)($system);
        }

        return $system;
    }

    /**
     * Run in single-process mode with the given runtime.
     */
    public function run(Runtime $runtime, ?LoggerInterface $logger = null): void
    {
        $system = $this->start($runtime, $logger);
        $system->run();
    }
}
