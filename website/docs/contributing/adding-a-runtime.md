---
title: Adding a Runtime
related:
  - runtimes/overview
  - contributing/adding-a-package
  - contributing/your-first-pr
---

# Adding a Runtime

When you want to add a new concurrency backend for Nexus — for example, a ReactPHP runtime or a pure-stream runtime — follow this checklist.

## Checklist

### 1. Implement the `Runtime` interface

The interface lives at `packages/nexus-runtime/src/Runtime/Runtime.php`. Every method must be implemented:

```php title="packages/nexus-my-runtime/src/Runtime/MyRuntime.php"
use Monadial\Nexus\Runtime\Async\FutureSlot;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Mailbox\Mailbox;
use Monadial\Nexus\Runtime\Mailbox\MailboxConfig;
use Monadial\Nexus\Runtime\Runtime\Cancellable;
use Monadial\Nexus\Runtime\Runtime\Runtime;

final class MyRuntime implements Runtime
{
    public function name(): string { /* ... */ }
    public function createMailbox(MailboxConfig $config): Mailbox { /* ... */ }
    public function createFutureSlot(): FutureSlot { /* ... */ }
    public function spawn(callable $actorLoop): string { /* ... */ }
    public function defer(callable $task): void { /* ... */ }
    public function scheduleOnce(Duration $delay, callable $callback): Cancellable { /* ... */ }
    public function scheduleRepeatedly(Duration $initialDelay, Duration $interval, callable $callback): Cancellable { /* ... */ }
    public function yield(): void { /* ... */ }
    public function sleep(Duration $duration): void { /* ... */ }
    public function run(): void { /* ... */ }
    public function shutdown(Duration $timeout): void { /* ... */ }
    public function isRunning(): bool { /* ... */ }
}
```

### 2. Ship a `Mailbox` implementation

The mailbox must match your runtime's concurrency primitive. `FiberRuntime` uses `FiberMailbox` (fiber-suspend on `dequeueBlocking`); `SwooleRuntime` uses `SwooleMailbox` (Swoole channel). Your runtime's mailbox should:

- Implement `Mailbox` (interface at `packages/nexus-core/src/Mailbox/Mailbox.php`)
- Implement `isClosed(): bool`
- Suspend the caller's concurrency unit (fiber, coroutine, etc.) in `dequeueBlocking()` without blocking the OS thread
- Wake all blocking waiters in `close()`

### 3. Add integration tests

Create a test directory under `tests/Integration/` named after your runtime. Every integration test must exercise a real `ActorSystem` backed by your runtime:

```php title="tests/Integration/MyRuntime/ActorLifecycleTest.php"
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\MyRuntime\Runtime\MyRuntime;

final class ActorLifecycleTest extends TestCase
{
    #[Test]
    public function basicTellReceive(): void
    {
        $runtime = new MyRuntime();
        $system = ActorSystem::create('test', $runtime);
        // ...
        $runtime->scheduleOnce(Duration::millis(500), fn() => $system->shutdown(Duration::seconds(1)));
        $system->run();
        self::assertSame($expected, $captured);
    }
}
```

Add the test suite to `phpunit.xml` and to the CI workflow under a new `integration-my-runtime` job.

### 4. Update `runtimes/overview.md`

Add your runtime to the decision tree in `website/docs/runtimes/overview.md`:

- Add a row to the comparison table with your runtime's concurrency model, I/O capabilities, and recommended use cases
- Update the "Which runtime should I use?" guidance to mention when your runtime is preferred

### 5. Create `runtimes/my-runtime.md`

Add a dedicated doc page at `website/docs/runtimes/my-runtime.md`. Follow the reference page template (see the style guide). Include:

- One-sentence synopsis
- Install: `composer require nexus-actors/runtime-my-runtime`
- How the concurrency primitive works
- `ActorSystem::create()` bootstrap example
- Known limitations or caveats

Add the page to `website/sidebars.js` under the Runtimes category.

### 6. Create a separate package

Follow the [adding a package](adding-a-package.md) checklist to create `packages/nexus-runtime-my-runtime/` with its own `composer.json`, README, and split.yml entry.

## See also

- [Runtimes overview](../runtimes/overview.md) — how runtimes compare
- [Adding a package](adding-a-package.md) — monorepo structure checklist
