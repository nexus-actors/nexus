---
sidebar_position: 11
title: Testing actors
related:
  - runtimes/step
  - best-practices/observability
  - core-concepts/behaviors
  - core-concepts/lifecycle
---

# Testing actors

90% of your actor test suite should use `StepRuntime`. It runs in microseconds, schedules deterministically, and gives you exact control over which message the actor processes next — no sleeps, no timing variance, no flakiness.

## The runtime rule

- **`StepRuntime`** for all unit tests. Cheapest, fastest, most predictable. Surfaces concurrency bugs that production runtimes hide.
- **`FiberRuntime`** for integration tests that exercise real I/O or true passivation timers.
- **`SwooleRuntime`** only in test suites that genuinely need Swoole coroutine semantics.

## `StepRuntime` basics

```php title="tests/Unit/Actor/CounterActorTest.php"
$runtime = new StepRuntime();
$system  = ActorSystem::create('test', $runtime, clock: $runtime->clock());

$ref = $system->spawn(Props::fromBehavior($counter), 'counter');
$ref->tell(new Increment());
$ref->tell(new Increment());

$runtime->step();   // process exactly one message
$runtime->step();   // process the next one
// or
$runtime->drain();  // process all pending messages
```

The runtime processes messages only when you call `step()` or `drain()`. There is no event loop, no sleep, no timing variance.

For time-based tests, advance the clock manually:

```php title="tests/Unit/Actor/TimerActorTest.php"
$ref->tell(new BeginTimer());
$runtime->advanceTime(Duration::seconds(30));
$runtime->drain();

self::assertTrue($captured->timerFired);
```

Every `scheduleOnce` and `scheduleRepeatedly` call reads from `TestClock`. Advancing it triggers scheduled callbacks deterministically.

## Test the actor's protocol, not the framework

Avoid testing with `FiberRuntime` in unit tests:

:::caution Testing the runtime, not the actor
```php title="tests/Unit/Actor/CounterActorTest.php" verify:lint-only
// This races, sometimes flakes, costs ~50ms of fiber scheduling,
// and tells you nothing a StepRuntime test wouldn't.
$system = ActorSystem::create('test', new FiberRuntime());
$ref    = $system->spawn(Props::fromBehavior($counter), 'c');
$ref->tell(new Increment());
$reply  = $ref->ask(new GetCount(), Duration::seconds(1))->await();
self::assertSame(1, $reply->count);
```
:::

Prefer:

```php title="tests/Unit/Actor/CounterActorTest.php"
$runtime = new StepRuntime();
$system  = ActorSystem::create('test', $runtime, clock: $runtime->clock());
$ref     = $system->spawn(Props::fromBehavior($counter), 'c');

$ref->tell(new Increment());
$runtime->step();

$reply = $this->probe->captureReply($ref, new GetCount(), $runtime);
self::assertSame(1, $reply->count);
```

Same coverage, microsecond runtime, deterministic result.

## TestProbe pattern

For ask-style tests, write a probe actor that captures replies:

```php title="tests/Support/TestProbe.php"
final class TestProbe
{
    /** @var list<object> */
    private array $received = [];

    /**
     * @template M of object
     * @param ActorRef<M> $target
     * @param M $msg
     */
    public function captureReply(ActorRef $target, object $msg, StepRuntime $r): object
    {
        $self = $this->system->spawn(
            Props::fromBehavior(Behavior::receive(
                fn($ctx, object $reply): Behavior => $this->capture($reply),
            )),
            'probe-' . spl_object_id($this),
        );
        $target->tell($msg, $self);
        $r->drain();

        return $this->received[count($this->received) - 1];
    }
}
```

The probe is an `ActorRef`. You pass it as the `sender` on the original message; the actor under test replies to it; `drain()` ensures the round trip completes before you assert.

## Test support utilities

`nexus-core/tests/Support/` ships three utilities included in `phpunit.xml`'s `<source>` block:

- **`TestRuntime`** — thin wrapper around `StepRuntime` with auto-draining for unit tests that don't need step-by-step control.
- **`TestMailbox`** — a `Mailbox` implementation that exposes its internals for asserting what's queued without dequeueing.
- **`TestClock`** — the deterministic clock to pair with `StepRuntime`.

```php title="tests/Unit/SomeActorTest.php"
use Monadial\Nexus\Core\Tests\Support\TestClock;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;

$clock   = new TestClock();
$runtime = new TestRuntime();
$system  = ActorSystem::create('test', $runtime, clock: $clock);
```

These count toward coverage — you're not testing untested support code.

## Persistence tests

For event-sourced actors, use `InMemoryEventStore`. The actor behaves identically to one backed by Postgres; the test asserts on the output (persisted events), not the actor's hidden state:

```php title="tests/Unit/Actor/WalletActorTest.php"
$store    = new InMemoryEventStore();
$behavior = EventSourcedBehavior::create(
    persistenceId: PersistenceId::of('Wallet', 'alice'),
    emptyState:    new WalletState(Money::zero()),
    commandHandler: $cmdHandler,
    eventHandler:   $evtHandler,
)->withEventStore($store)->toBehavior();

$ref = $system->spawn(Props::fromBehavior($behavior), 'wallet-alice');
$ref->tell(new Deposit(Money::of(100)));
$runtime->drain();

$events = $store->load(PersistenceId::of('Wallet', 'alice'));
self::assertCount(1, $events);
self::assertInstanceOf(MoneyDeposited::class, $events[0]);
```

## Anti-patterns to avoid

**Reflection into `ActorCell`.** If a test reaches for `ReflectionClass::getProperty('currentBehavior')`, it's coupled to framework internals and will break on any internal refactor.

**Mocking the framework.** Don't mock `Mailbox`, `ActorRef`, or `Runtime`. The real implementations are deterministic under `StepRuntime`. Mocking introduces a behaviour gap between test and production.

Test through the actor's public protocol: send messages, advance time, observe replies. That's the contract you ship; test the contract.

## When to use the production runtime

Integration tests that genuinely require production semantics:

- Real DB round trips → `tests/Integration/Doctrine/`
- Swoole coroutine semantics → `tests/Integration/Swoole/`
- Thread-pool routing → `tests/Integration/WorkerPool/`
- Graceful-shutdown wiring → boot the app, send SIGTERM, count FATAL lines

Keep integration tests small and few. Run them less often than unit tests. The `make test-*` targets segment them so CI can parallelise.

## What "passing" means

A well-written actor test is:

1. **Deterministic.** Run it 100 times; pass 100 times.
2. **Fast.** Unit tests under 10ms each, ideally under 1ms.
3. **Loud on failure.** A clear assertion error on the actual misbehaviour, not a timeout on something unrelated.
4. **Focused.** "Deposit increases balance" is one test; "deposit then withdraw then deposit again" is three tests.

If your suite drifts from those properties, the runtime — not the actor — is what's being tested. Switch to `StepRuntime` and most drift disappears.

## Next steps

- [Step runtime](../runtimes/step.md) — `StepRuntime`, `TestClock`, and deterministic scheduling in depth
- [Behaviors](../core-concepts/behaviors.md) — understanding what `drain()` is actually processing
- [Observability](./observability.md) — `StepRuntime` as a debugging tool for timing-sensitive bugs in production
