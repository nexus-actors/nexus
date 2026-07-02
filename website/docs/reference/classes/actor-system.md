---
title: ActorSystem
sidebar_position: 2
related:
  - core-concepts/actors
  - getting-started/quick-start
  - reference/classes/actor-ref
  - reference/classes/props
---

# ActorSystem

The entry point for spawning actors and driving the actor system event loop.

## What it does

`ActorSystem` is the top-level container for your actor hierarchy. You typically
create one per process, configure it with a [Runtime](../../runtimes/overview) (
`FiberRuntime`, `SwooleRuntime`, or `StepRuntime`), and use it to spawn the root
actors of your supervision tree. Every actor spawned through the system shares a
single runtime, clock, logger, and event dispatcher. The system assigns each actor
a unique path under `/user/<name>` and enforces that no two sibling actors share the
same name. Call `run()` to start the event loop — it blocks until `shutdown()` is
called, which drains in-flight messages within the given timeout before stopping
the runtime.

## Example

```php title="src/MyApp.php"
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\FiberRuntime;

$runtime = new FiberRuntime();
$system  = ActorSystem::create('my-app', $runtime);

$ref = $system->spawn(Props::fromBehavior($greeterBehavior), 'greeter');
$ref->tell(new Greet('world'));

// Shut down after 500 ms
$runtime->scheduleOnce(
    Duration::millis(500),
    fn() => $system->shutdown(Duration::seconds(1)),
);

$system->run(); // blocks until shutdown completes
```

## Key methods

- `ActorSystem::create(string $name, Runtime $runtime, ?ClockInterface $clock, ?LoggerInterface $logger, ?EventDispatcherInterface $dispatcher): self` — factory; all optional parameters fall back to safe no-op defaults.
- `spawn(Props $props, string $name): ActorRef` — spawn a named root actor under `/user`.
- `spawnAnonymous(Props $props): ActorRef` — spawn a root actor with an auto-generated name.
- `run(): void` — start the runtime event loop (blocks until shutdown).
- `shutdown(Duration $timeout): void` — send `PoisonPill` to all root actors, wait up to `$timeout`, then force-stop the runtime.
- `deadLetters(): DeadLetterRef` — the null-object sink for undeliverable messages.
- `writerId(): Ulid` — unique writer ID stamped on every persisted event (single-writer guarantee).

## Full API reference

[Full method list and class hierarchy](https://api.nexusactors.com/classes/Monadial-Nexus-Core-Actor-ActorSystem.html)

## See also

- [Actors concept](../../core-concepts/actors) — high-level model and supervision trees
- [Props](props) — actor spawn configuration
- [ActorRef](actor-ref) — the handle returned by `spawn()`
- [Runtimes overview](../../runtimes/overview) — choosing between Fiber, Swoole, and Step
