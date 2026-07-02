---
title: Worker pool bootstrap
related:
  - scaling/overview
  - scaling/configuration
  - packages/worker-pool-swoole
  - packages/worker-pool
---

# Worker pool bootstrap

Three entry points exist for starting a worker pool: `WorkerPoolApp` (recommended for most applications), `WorkerPoolBootstrap` (lower-level), and `WorkerStartHandler` (direct interface for maximum control).

## WorkerPoolApp

Extend `WorkerPoolApp`, override `configure()`, and call `run()` on the subclass:

```php title="src/App/MyApp.php"
<?php

declare(strict_types=1);

use Monadial\Nexus\WorkerPool\Swoole\WorkerPoolApp;
use Monadial\Nexus\WorkerPool\WorkerNode;
use Monadial\Nexus\WorkerPool\WorkerPoolConfig;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Actor\Behavior;

final class MyApp extends WorkerPoolApp
{
    protected function configure(WorkerNode $node): void
    {
        $node->spawn(
            Props::fromBehavior(
                Behavior::receive(static fn ($ctx, $msg) => Behavior::same()),
            ),
            'orders',
        );
    }
}

MyApp::run(WorkerPoolConfig::withThreads(swoole_cpu_num()));
```

`configure()` is called once per worker thread with a fresh `WorkerNode`. The class is re-instantiated in each thread, so closures inside `configure()` do not cross thread boundaries.

## WorkerPoolBootstrap

Use `WorkerPoolBootstrap` when you need to wire up the handler class separately from the bootstrap entry point:

```php title="src/bootstrap.php"
<?php

declare(strict_types=1);

use Monadial\Nexus\WorkerPool\Swoole\WorkerPoolBootstrap;
use Monadial\Nexus\WorkerPool\WorkerPoolConfig;

WorkerPoolBootstrap::create(WorkerPoolConfig::withThreads(4))
    ->withHandler(MyWorkerStartHandler::class)
    ->run();
```

`withHandler()` accepts a `class-string<WorkerStartHandler>`. The class is instantiated fresh in each thread — no shared state.

## WorkerStartHandler

Implement `WorkerStartHandler` directly when you need the full interface without the `WorkerPoolApp` base class:

```php title="src/App/MyWorkerStartHandler.php"
<?php

declare(strict_types=1);

use Monadial\Nexus\WorkerPool\WorkerStartHandler;
use Monadial\Nexus\WorkerPool\WorkerNode;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Actor\Behavior;

final class MyWorkerStartHandler implements WorkerStartHandler
{
    public function onWorkerStart(WorkerNode $node): void
    {
        $node->spawn(Props::fromBehavior($behavior), 'orders');
    }
}
```

## Looking up actors

After spawning, retrieve an actor reference by path from within a handler:

```php title="src/App/MyWorkerStartHandler.php"
$ref = $node->actorFor('/user/orders');  // returns null if not registered
```

## Example: distributed counter

Eight named counters spread across four workers via the hash ring:

```php title="src/App/CounterApp.php"
<?php

declare(strict_types=1);

use Monadial\Nexus\WorkerPool\Swoole\WorkerPoolApp;
use Monadial\Nexus\WorkerPool\WorkerNode;
use Monadial\Nexus\WorkerPool\WorkerPoolConfig;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use Monadial\Nexus\Core\Actor\Props;

readonly class Increment {}
readonly class GetCount
{
    public function __construct(public \Monadial\Nexus\Core\Actor\ActorRef $replyTo) {}
}

final class CounterApp extends WorkerPoolApp
{
    protected function configure(WorkerNode $node): void
    {
        for ($i = 0; $i < 8; $i++) {
            $node->spawn(
                Props::fromBehavior(
                    Behavior::withState(
                        0,
                        static function ($ctx, $msg, int $count) {
                            if ($msg instanceof Increment) {
                                return BehaviorWithState::next($count + 1);
                            }

                            if ($msg instanceof GetCount) {
                                $msg->replyTo->tell($count);
                            }

                            return BehaviorWithState::same();
                        },
                    ),
                ),
                "counter-{$i}",
            );
        }
    }
}

CounterApp::run(WorkerPoolConfig::withThreads(4));
```

Each `"counter-N"` name hashes to one of the four workers. Workers that hash `"counter-N"` to themselves get a `LocalActorRef`; workers that hash it to another worker get a `WorkerActorRef` that routes transparently.

## See also

- [Scaling overview](./overview.md) — architecture, message flow, and location transparency
- [Configuration](./configuration.md) — `WorkerPoolConfig` and `ConsistentHashRing` placement
- [Worker Pool Swoole package](../packages/worker-pool-swoole.md) — full `WorkerPoolBootstrap` API reference
