# nexus-messenger-console-swoole

Threaded Symfony Console runner for the [nexus-messenger](../nexus-messenger) bridge using the [Swoole worker pool](../nexus-worker-pool-swoole).

Adds the `nexus:messenger:consume-threads` command: boots N Swoole worker threads, each with its own `ActorSystem`, `SwooleRuntime`, and a fresh transport connection (via `ThreadedConsumerBootstrap::receiver()`). The broker naturally load-balances across threads.

## Install

```bash
composer require nexus-actors/messenger-console-swoole
```

Requires PHP 8.5.7+, `ext-swoole >= 6.2.1`, and a Swoole build with thread support (`--enable-swoole-thread`).

## Usage

### 1. Implement ThreadedConsumerBootstrap

```php
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Messenger\Console\Swoole\ThreadedConsumerBootstrap;
use Monadial\Nexus\Messenger\Routing\MapMessageRouter;
use Monadial\Nexus\Messenger\Routing\MessageRouter;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

final class OrderConsumerBootstrap implements ThreadedConsumerBootstrap
{
    public function setup(ActorSystem $system): MessageRouter
    {
        $ref = $system->spawn(Props::fromFactory(fn() => new OrdersActor()), 'orders');

        return new MapMessageRouter([OrderPlaced::class => $ref]);
    }

    public function receiver(): ReceiverInterface
    {
        // Return a FRESH connection per thread — the broker load-balances
        return new RedisTransport(Redis::connect(getenv('REDIS_URL')));
    }
}
```

### 2. Register the command

```php
// bin/console
$app = new Application('nexus-worker', '1.0.0');
$app->add(new ThreadedConsumeCommand(OrderConsumerBootstrap::class));
$app->run();
```

### 3. Run

```bash
# 4 threads, 2 receivers per thread
bin/console nexus:messenger:consume-threads --threads=4 --receivers=2

# Per-thread limits — each thread recycles after 10 000 messages
bin/console nexus:messenger:consume-threads --threads=4 --limit=10000 --memory-limit=256M
```

## Key concepts

**Per-thread semantics:** All limit options (`--limit`, `--memory-limit`, `--time-limit`) are evaluated per thread. Each thread has its own `LifecycleWatchdog`. Setting `--limit=1000` on a 4-thread pool means each thread processes up to 1000 messages before recycling — not 1000 total.

**Fresh transport per thread:** `ThreadedConsumerBootstrap::receiver()` is called inside each thread. Return a new connection object every time — never share a connection created on the main thread.

**Signal handling:** The main thread blocks in `Swoole\Thread\Pool::start()`. SIGTERM stops the pool; each worker thread shuts down its `ActorSystem` cleanly. Use a process manager (systemd, supervisord, Docker) to control the worker lifecycle.

## See also

- [nexus-messenger-console](../nexus-messenger-console) — single-threaded `FiberRuntime` runner
- [nexus-worker-pool-swoole](../nexus-worker-pool-swoole) — underlying thread pool
- [nexus-messenger](../nexus-messenger) — transport bridge and `MessengerBridge` facade
