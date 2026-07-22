<?php

declare(strict_types=1);

namespace Monadial\Nexus\App;

use Monadial\Nexus\App\Exception\UnknownRootActorException;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Runtime\Duration;

use function array_keys;

/**
 * A started application: the live {@see ActorSystem} plus a registry of the root
 * actor handles spawned from {@see NexusApp}'s definitions, keyed by name.
 *
 * Returned by {@see NexusApp::start()} and passed to the {@see NexusApp::onStart()}
 * callback so callers can retrieve typed handles to root actors — `start()` used to
 * discard the spawned refs, forcing real applications to bypass the DSL. Roots are
 * registered in definition order, which is also their spawn order, so a later root
 * can be wired to an earlier one via {@see ref()}.
 *
 * Owns shutdown: call {@see run()} to block on the runtime loop or {@see shutdown()}
 * to drain gracefully.
 *
 * @psalm-api
 */
final readonly class StartedApp
{
    /**
     * @param array<string, ActorRef<object>> $refs root actor handles keyed by registered name, in spawn order
     */
    public function __construct(private ActorSystem $system, private array $refs) {}

    /**
     * The handle for the root actor registered under $name.
     *
     * @return ActorRef<object>
     * @throws UnknownRootActorException when no root was registered under $name
     */
    public function ref(string $name): ActorRef
    {
        return $this->refs[$name] ?? throw new UnknownRootActorException($name, array_keys($this->refs));
    }

    public function has(string $name): bool
    {
        return isset($this->refs[$name]);
    }

    /**
     * All root actor handles keyed by name, in spawn order.
     *
     * @return array<string, ActorRef<object>>
     */
    public function refs(): array
    {
        return $this->refs;
    }

    public function system(): ActorSystem
    {
        return $this->system;
    }

    /**
     * Start the runtime event loop, blocking until the system is shut down.
     */
    public function run(): void
    {
        $this->system->run();
    }

    /**
     * Drain the system gracefully within the given deadline.
     */
    public function shutdown(Duration $timeout): void
    {
        $this->system->shutdown($timeout);
    }
}
