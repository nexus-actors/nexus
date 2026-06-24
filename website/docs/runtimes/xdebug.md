---
title: Debugging with Xdebug
related:
  - runtimes/fiber
  - runtimes/swoole
  - runtimes/overview
  - guides/standalone-integration
---

# Debugging with Xdebug

Xdebug ships in the `php-fiber` Docker target. The `php-swoole` target deliberately omits it: Xdebug and Swoole both hook `zend_execute_ex()` and conflict at shutdown, causing segfaults. Use `php-fiber` for all step-through debugging of actor logic.

## Connecting your IDE

The `php-fiber` container starts Xdebug in **trigger** mode by default (`XDEBUG_START_WITH_REQUEST=trigger`). It listens on port **9003** (`XDEBUG_CLIENT_PORT`) and connects back to `host.docker.internal` (`XDEBUG_CLIENT_HOST`).

### PhpStorm setup

1. Open **Settings → PHP → Debug** and confirm the debug port is `9003`.
2. Open **Settings → PHP → Servers**, add a server named `nexus-fiber`:
   - Host: `localhost`
   - Port: `9000` (or whatever your `docker-compose.yml` maps)
   - Check **Use path mappings** and map the project root to `/var/www/html` (the container's working directory).
3. Set a breakpoint, then start a **PHP Remote Debug** run configuration with server `nexus-fiber` and IDE key `PHPSTORM`.

To trigger a debug session for a single CLI command, prefix the command with `XDEBUG_SESSION=1`:

```bash title="terminal"
docker compose exec php env XDEBUG_SESSION=1 vendor/bin/phpunit \
    packages/nexus-core/tests/Unit/Actor/ActorSystemTest.php
```

For VS Code, install the **PHP Debug** extension and set `"port": 9003` in `launch.json`. Path mappings work identically to PhpStorm.

## Where to place breakpoints in actor code

Actor handlers run inside PHP fibers. The fiber suspends inside `dequeueBlocking()` while waiting for the next message — placing a breakpoint there will pause execution before any message arrives, which is rarely useful.

Place breakpoints **inside the handler body**, not on the `Behavior::receive()` call itself:

<!-- verify:skip: illustrates breakpoint placement in a running actor system -->
```php title="src/Actor/CounterActor.php" verify:skip
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\BehaviorWithState;

$behavior = Behavior::withState(
    0,
    static function (ActorContext $ctx, object $msg, int $count): BehaviorWithState {
        // ← set your breakpoint HERE, not on the line above
        if ($msg instanceof Increment) {
            return BehaviorWithState::next($count + 1);
        }

        if ($msg instanceof GetCount) {
            $ctx->sender()?->tell(new CountResult($count));
        }

        return BehaviorWithState::same();
    },
);
```

When the breakpoint fires, `$count` holds the actor's current state and `$msg` holds the message being processed. `$ctx` gives you access to the full actor context.

## Stepping through the counter actor example

The `examples/smoke.php` counter actor is the simplest entry point for debugging. Run it under Xdebug with:

```bash title="terminal"
docker compose exec php env XDEBUG_SESSION=1 php examples/smoke.php
```

With a breakpoint set on the handler body, each `Increment` message pauses execution and lets you inspect `$count` evolving from `0` upward.

## Caveats

:::caution Xdebug is not available in the Swoole container
The `php-swoole` target does not include Xdebug. Attempting to install it alongside Swoole causes `zend_execute_ex` conflicts that segfault at shutdown. There is no supported way to use step-through debugging with Swoole-specific actor code.
:::

- **Log-based debugging for Swoole** — use `$ctx->log()->debug(...)` (PSR-3) and inspect logs with `docker compose logs php-swoole`. The `SwooleRuntime` emits structured timing data you can grep.
- **Dump-based debugging** — `\Symfony\Component\VarDumper\VarDumper::dump($value)` works in both containers and writes to STDOUT, which appears in `docker compose logs`.
- **Step runtime for deterministic replay** — for logic bugs that do not depend on Swoole, switch to `StepRuntime` in a unit test. `StepRuntime::step()` processes exactly one message per call, giving you full control without needing a debugger attached.
- **Fiber suspension points** — Xdebug handles PHP fibers correctly from PHP 8.1+. You can step across `Fiber::suspend()` boundaries. However, the Xdebug call stack may show internal fiber frames; look for your handler closure in the call stack, not the topmost frame.
- **Performance** — Xdebug in trigger mode adds no overhead when no IDE is listening. It adds roughly 2–3× overhead when a session is active. Do not run performance benchmarks with `XDEBUG_SESSION=1`.

## Overriding Xdebug settings at startup

The default `xdebug.mode=debug` covers step debugging. Override it per-run via environment variables:

```bash title="terminal"
# Profile instead of debug
docker compose exec php env XDEBUG_MODE=profile XDEBUG_SESSION=1 php examples/smoke.php

# Coverage only (for CI — no network connection required)
docker compose exec php env XDEBUG_MODE=coverage vendor/bin/phpunit
```

The `docker/Dockerfile` `xdebug` stage sets the defaults; Docker Compose can override them with `environment:` entries in `docker-compose.yml` without rebuilding the image.

## See also

- [Fiber runtime](./fiber.md) — how actor fibers are scheduled and suspended
- [Step runtime](./step.md) — deterministic single-step execution for test debugging
- [Swoole runtime](./swoole.md) — production runtime; Xdebug incompatibility explained
