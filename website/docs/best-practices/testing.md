---
sidebar_position: 11
title: Testing actors
---

# Testing actors

> *"How do I write tests that aren't flaky and aren't 200ms each?"*

The framework's `StepRuntime` is the answer. It schedules deterministically,
runs in microseconds, and gives you exact control over what message
the actor processes next.

## The rule

> **Use `StepRuntime` for unit tests. Use `FiberRuntime` for
> integration tests that hit real I/O. Use `SwooleRuntime` only in
> the test suites that genuinely need Swoole semantics.**

90% of your test suite should be `StepRuntime`. It's the cheapest,
fastest, most predictable option, and it surfaces concurrency bugs
that production runtimes hide.

## `StepRuntime` basics

```php
$runtime = new StepRuntime();
$system = ActorSystem::create('test', $runtime, clock: $runtime->clock());

$ref = $system->spawn(Props::fromBehavior($counter), 'counter');
$ref->tell(new Increment());
$ref->tell(new Increment());

$runtime->step();   // process exactly one message
$runtime->step();   // process the next one
// or
$runtime->drain(); // process all pending messages
```

The runtime processes messages only when you tell it to. There's no
event loop, no sleep, no timing variance.

For time-based tests, advance the clock manually:

```php
$ref->tell(new BeginTimer());
$runtime->advanceTime(Duration::seconds(30));
$runtime->drain();

// Assert the timer fired
```

`StepRuntime` exposes a `TestClock` you pass to `ActorSystem::create`.
Every `scheduleOnce` / `scheduleRepeatedly` reads from that clock;
advancing it triggers the scheduled callbacks deterministically.

## Test the behavior, not the framework

Common anti-pattern:

```php
// Don't do this
public function test_actor_increments(): void
{
    $system = ActorSystem::create('test', new FiberRuntime());
    $ref = $system->spawn(Props::fromBehavior($counter), 'c');
    $ref->tell(new Increment());

    $reply = $ref->ask(new GetCount(), Duration::seconds(1))->await();

    self::assertSame(1, $reply->count);
}
```

This is a stress test of the runtime, not of your actor. It races,
sometimes flakes, costs ~50ms of fiber scheduling, and tells you
nothing about the actor's correctness that a `StepRuntime` test
wouldn't.

Better:

```php
public function test_actor_increments(): void
{
    $runtime = new StepRuntime();
    $system = ActorSystem::create('test', $runtime, clock: $runtime->clock());
    $ref = $system->spawn(Props::fromBehavior($counter), 'c');

    $ref->tell(new Increment());
    $runtime->step();

    // Get the actor's state directly via a Diagnose command, or via
    // a TestProbe that captures replies.
    $reply = $this->probe->ask($ref, new GetCount());
    self::assertSame(1, $reply->count);
}
```

Same coverage, microsecond runtime, deterministic.

## TestProbe pattern

For ask-style tests, write a tiny probe actor that captures replies:

```php
final class TestProbe
{
    /** @var list<object> */
    private array $received = [];

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

The probe is an `ActorRef`; you `tell` it the reply by passing it as
`sender` on the original ask. `drain()` ensures the message round
trip completes; you can then assert on what was captured.

## Use the `Test` support utilities

The `nexus-core/tests/Support/` directory ships:

- `TestRuntime` — a thin wrapper around `StepRuntime` that suits
  unit tests where you want auto-draining
- `TestMailbox` — a `Mailbox` implementation that exposes its
  internals so you can assert on what's queued without dequeueing
- `TestClock` — the deterministic clock you'd pair with
  `StepRuntime`

```php
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Core\Tests\Support\TestClock;

$clock = new TestClock();
$runtime = new TestRuntime();
$system = ActorSystem::create('test', $runtime, clock: $clock);
```

These are included in `phpunit.xml`'s `<source>` so they count toward
coverage; you're not testing untested support code.

## Persistence tests

For event-sourced or durable-state actors:

```php
$store = new InMemoryEventStore();

$behavior = EventSourcedBehavior::create(
    persistenceId: PersistenceId::of('Wallet', 'alice'),
    emptyState: new WalletState(Money::zero()),
    commandHandler: $cmdHandler,
    eventHandler: $evtHandler,
)
->withEventStore($store)
->toBehavior();

$ref = $system->spawn(Props::fromBehavior($behavior), 'wallet-alice');
$ref->tell(new Deposit(Money::of(100)));
$runtime->drain();

// Inspect what got persisted
$events = $store->load(PersistenceId::of('Wallet', 'alice'));
self::assertCount(1, $events);
self::assertInstanceOf(MoneyDeposited::class, $events[0]);
```

`InMemoryEventStore` is a real `EventStore`; the actor behaves
identically to one backed by Postgres. The test asserts on the
*output* (persisted events), not the actor's hidden state.

## Don't test private internals

Two related anti-patterns:

**1. Reflection into `ActorCell`.** If a test reaches for
`ReflectionClass::getProperty('currentBehavior')`, the test is
coupled to framework internals and will break on a refactor.

**2. Mocking the framework.** Don't mock `Mailbox`, `ActorRef`,
`Runtime`. The real implementations are deterministic under
`StepRuntime`. Mocking introduces a behaviour gap between test and
production.

Test through the actor's public protocol: send messages, advance
time, observe replies. That's the contract you ship; that's the
contract you test.

## When you do need the production runtime

Integration tests that exercise:

- Real DB roundtrips (use `tests/Integration/Doctrine/...`)
- Swoole coroutine semantics (use `tests/Integration/Swoole/...`)
- Thread-pool routing (use `tests/Integration/WorkerPool/...`)
- Graceful-shutdown wiring (boot the wallet-app, send SIGTERM, count
  FATAL lines)

Run them under the actual production runtime. Keep them small,
keep them few, run them less often than unit tests. The `make
test-*` targets segment them so CI can parallelise.

## Patterns to copy

The wallet-app's tests are mostly integration tests (the framework
itself is heavily unit-tested), so for unit-test patterns look at
`packages/nexus-core/tests/Unit/`. The
`packages/nexus-doctrine-orm/tests/Unit/Behavior/EntityBehavior*`
suite is a good reference for testing complex builders.

For passivation-style tests, see
`tests/Integration/Doctrine/Orm/EntityBehavior/PassivationTest.php` —
it uses `FiberRuntime` because passivation needs real timers, but
shows the schedule-and-assert pattern that translates to
`StepRuntime` for everything else.

## What "passing" means

A passing test should:

1. **Be deterministic.** Run it 100 times; pass 100 times.
2. **Be fast.** Unit tests under 10ms each, ideally under 1ms.
3. **Fail loudly.** A clear assertion error on the actual misbehaviour,
   not a timeout on something unrelated.
4. **Cover one fact.** "Deposit increases balance" is a test;
   "Deposit then withdraw then deposit again behaves correctly" is
   probably three tests.

If your suite drifts from those, it's because the runtime, not the
actor, is the thing being tested. Switch to `StepRuntime` and most
drift goes away.
