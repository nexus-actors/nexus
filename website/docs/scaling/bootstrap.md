# Bootstrap

## Prerequisites

- ZTS PHP 8.5+
- Swoole 6.0+ with `--enable-swoole-thread`

## WorkerPoolApp (recommended)

Extend `WorkerPoolApp`, override `configure()`, call `run()`:

```php
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
                Behavior::receive(static fn($ctx, $msg) => Behavior::same()),
            ),
            'orders',
        );
    }
}

MyApp::run(WorkerPoolConfig::withThreads(swoole_cpu_num()));
```

`configure()` is called once per worker thread with a fresh `WorkerNode`. Closures inside
`configure()` are safe — the class is re-instantiated in each thread, so no closure crosses
a thread boundary.

## WorkerPoolBootstrap (lower-level)

```php
use Monadial\Nexus\WorkerPool\Swoole\WorkerPoolBootstrap;
use Monadial\Nexus\WorkerPool\WorkerPoolConfig;

WorkerPoolBootstrap::create(WorkerPoolConfig::withThreads(4))
    ->withHandler(MyWorkerStartHandler::class)
    ->run();
```

`withHandler()` accepts a `class-string<WorkerStartHandler>`. The class is instantiated
fresh in each thread.

## WorkerStartHandler

Implement `WorkerStartHandler` directly when you need more control:

```php
use Monadial\Nexus\WorkerPool\WorkerStartHandler;
use Monadial\Nexus\WorkerPool\WorkerNode;

final class MyWorkerStartHandler implements WorkerStartHandler
{
    public function onWorkerStart(WorkerNode $node): void
    {
        $node->spawn(Props::fromBehavior($behavior), 'orders');
    }
}
```

## Looking up actors

After spawning, look up a ref by path from within a handler:

```php
$ref = $node->actorFor('/user/orders');  // null if not registered
```

## Example: distributed counter

```php
use Monadial\Nexus\WorkerPool\Swoole\WorkerPoolApp;
use Monadial\Nexus\WorkerPool\WorkerNode;
use Monadial\Nexus\WorkerPool\WorkerPoolConfig;

readonly class Increment {}
readonly class GetCount { public function __construct(public ActorRef $replyTo) {} }

final class CounterApp extends WorkerPoolApp
{
    protected function configure(WorkerNode $node): void
    {
        // Spawn 8 named counters — each lands on the worker its name hashes to
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
