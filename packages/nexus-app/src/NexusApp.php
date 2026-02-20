<?php
declare(strict_types=1);

namespace Monadial\Nexus\App;

use Closure;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Runtime\Runtime;

/**
 * @psalm-api
 *
 * Application kernel for Nexus actor applications.
 *
 * Register actors, configure the runtime, and run:
 *
 *     NexusApp::create('my-app')
 *         ->actor('orders', Props::fromBehavior($orderBehavior))
 *         ->actor('payments', Props::fromFactory(fn() => new PaymentActor()))
 *         ->run(new SwooleRuntime());
 */
final class NexusApp
{
    /** @var list<ActorDefinition<object>> */
    private array $definitions = [];

    /** @var ?Closure(ActorSystem): void */
    private ?Closure $startCallback = null;

    private function __construct(private readonly string $appName)
    {
    }

    public static function create(string $name): self
    {
        return new self($name);
    }

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
     * Run in single-process mode with the given runtime.
     */
    public function run(Runtime $runtime): void
    {
        $system = ActorSystem::create($this->appName, $runtime);

        foreach ($this->definitions as $definition) {
            $system->spawn($definition->props, $definition->name);
        }

        if ($this->startCallback !== null) {
            ($this->startCallback)($system);
        }

        $system->run();
    }
}
