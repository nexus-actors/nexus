# Receive Timeout & Idle Passivation Implementation Plan (Plan 4)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `ReceiveTimeout` semantics to `nexus-core`, wire it into `EntityBehavior` for idle-passivation with transparent rehydration, and make `EntityRefFactory` self-cleaning. Net result: long-lived entity actors release their dedicated `EntityManager` + `Connection` when idle, automatically reload from DB on the next message.

**Architecture:** Three new symbols in `nexus-core`: `Lifecycle\ReceiveTimeout` signal, `ActorContext::setReceiveTimeout(?Duration)` method, and the `ActorCell` integration that resets a single timer per user-message dispatch. One opt-in setter on `EntityBehaviorBuilder` (`withReceiveTimeout`). The `EntityBehaviorRunner` delivers `ReceiveTimeout` → `Behavior::stopped()` and the existing `PostStop` cleanup closes the EM + Connection. `EntityRefFactory::of()` checks `ActorRef::isAlive()` before returning a cached ref; dead refs are dropped and respawned. Same primitives can later be wired into `EventSourcedBehavior` and `DurableStateBehavior` — out of scope here.

**Tech Stack:** PHP 8.5 strict, Psalm strict-level 1, PHPCS PER-CS2.0 + Slevomat, PHPUnit 13, GrumPHP. Branch `feat/nexus-doctrine`.

**Depends on:** Plans 1–3 merged. All `nexus-doctrine-orm` symbols (`EntityBehavior`, `EntityBehaviorBuilder`, `EntityBehaviorRunner`, `EntityRefFactory`, `EntityRefFactoryBuilder`) are available.

---

## Spec → Plan map

| Spec section | Tasks |
|---|---|
| `Lifecycle\ReceiveTimeout` signal | T1 |
| `ActorContext::setReceiveTimeout` + `ActorCell` wiring | T2–T3 |
| `EntityBehaviorBuilder::withReceiveTimeout` | T4 |
| `EntityBehaviorRunner` integration | T5 |
| `EntityRefFactory` self-cleaning + `withReceiveTimeout` forwarding | T6 |
| Integration tests | T7–T8 |
| Docs + final gate | T9–T10 |

---

## File structure

**New files in `packages/nexus-core/`:**

```
packages/nexus-core/src/Lifecycle/
└── ReceiveTimeout.php                              new lifecycle signal
```

**Modified files in `packages/nexus-core/`:**

```
packages/nexus-core/src/Actor/ActorContext.php      add setReceiveTimeout signature
packages/nexus-core/src/Actor/ActorCell.php         timer state + reset logic
```

**Modified files in `packages/nexus-doctrine-orm/`:**

```
packages/nexus-doctrine-orm/src/Behavior/EntityBehaviorBuilder.php       new field + setter
packages/nexus-doctrine-orm/src/Behavior/EntityBehaviorRunner.php        signal handling
packages/nexus-doctrine-orm/src/Behavior/EntityRefFactory.php            isAlive() check
packages/nexus-doctrine-orm/src/Behavior/EntityRefFactoryBuilder.php     forward timeout
```

**New test files:**

```
packages/nexus-core/tests/Unit/Lifecycle/ReceiveTimeoutTest.php
packages/nexus-core/tests/Unit/Actor/ActorCellReceiveTimeoutTest.php
packages/nexus-doctrine-orm/tests/Unit/Behavior/EntityBehaviorBuilderReceiveTimeoutTest.php
packages/nexus-doctrine-orm/tests/Unit/Behavior/EntityRefFactoryEvictionTest.php
tests/Integration/Fiber/ReceiveTimeoutPassivationTest.php
tests/Integration/Doctrine/Orm/EntityBehavior/PassivationTest.php
```

**Modified docs:**

```
website/docs/core-concepts/lifecycle.md             document ReceiveTimeout signal
website/docs/doctrine/entity-behavior.md            document withReceiveTimeout
website/docs/packages/doctrine-orm.md               new builder method in class table
```

---

## Conventions

Same as Plans 1–3:
- Docker for everything (`docker compose exec -T php-fiber vendor/bin/phpunit …`)
- GrumPHP gates each commit; **GPG broken — `git commit --no-gpg-sign`**; no `Co-Authored-By`
- Leave alone: `.deptrac.cache`, `packages/nexus-http-toolkit/src/Middleware/BodySizeLimitMiddleware.php`
- Style: PER-CS2.0 + Slevomat. `final readonly` value objects.

---

## Task 1: `Lifecycle\ReceiveTimeout` signal class

**Files:**
- Create: `packages/nexus-core/src/Lifecycle/ReceiveTimeout.php`
- Create: `packages/nexus-core/tests/Unit/Lifecycle/ReceiveTimeoutTest.php`

### Step 1: Write the failing test

`packages/nexus-core/tests/Unit/Lifecycle/ReceiveTimeoutTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Lifecycle;

use Monadial\Nexus\Core\Lifecycle\ReceiveTimeout;
use Monadial\Nexus\Runtime\Duration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReceiveTimeout::class)]
final class ReceiveTimeoutTest extends TestCase
{
    #[Test]
    public function carriesConfiguredDuration(): void
    {
        $signal = new ReceiveTimeout(Duration::seconds(30));
        self::assertTrue($signal->configured->equals(Duration::seconds(30)));
    }
}
```

### Step 2: Run, verify failure

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-core/tests/Unit/Lifecycle/ReceiveTimeoutTest.php
```

### Step 3: Implement

`packages/nexus-core/src/Lifecycle/ReceiveTimeout.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Lifecycle;

use Monadial\Nexus\Runtime\Duration;

/**
 * Lifecycle signal delivered to an actor when no user message has arrived
 * within the duration configured via ActorContext::setReceiveTimeout().
 *
 * Handle via behavior->onSignal(...). Typical use: return Behavior::stopped()
 * to passivate. The actor's PostStop handler runs as normal — release any
 * resources there.
 *
 * @psalm-api
 */
final readonly class ReceiveTimeout
{
    public function __construct(public Duration $configured) {}
}
```

### Step 4: Verify + commit

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-core/tests/Unit/Lifecycle/ReceiveTimeoutTest.php
git add packages/nexus-core/src/Lifecycle/ReceiveTimeout.php packages/nexus-core/tests/Unit/Lifecycle/ReceiveTimeoutTest.php
git commit --no-gpg-sign -m "feat(core): add ReceiveTimeout lifecycle signal"
```

---

## Task 2: `ActorContext::setReceiveTimeout` interface method

**Files:**
- Modify: `packages/nexus-core/src/Actor/ActorContext.php` — add abstract method
- Modify: any existing fakes / stubs in `packages/nexus-core/tests/Support/` that implement `ActorContext` (`TestActorContext`, etc.) — add no-op or recording implementations

### Step 1: Inspect the current `ActorContext`

```bash
grep -n "public function\|abstract" packages/nexus-core/src/Actor/ActorContext.php | head -30
ls packages/nexus-core/tests/Support/
grep -rn "implements ActorContext\|extends ActorContext" packages/nexus-core/tests/ packages/ | head -10
```

Note: `ActorContext` may be an abstract class or interface. Mirror the existing method style (e.g. `public abstract function stash(): void`).

### Step 2: Add the method signature

```php
/**
 * Configure a receive-timeout: if no user message arrives within $timeout
 * after the last user-message dispatch, the actor receives a
 * Monadial\Nexus\Core\Lifecycle\ReceiveTimeout signal via onSignal().
 *
 * Call with null to cancel. Re-calling with a new Duration replaces the
 * current setting; the first user message after the call uses the new
 * timeout.
 */
public function setReceiveTimeout(?Duration $timeout): void;
```

### Step 3: Update fakes/stubs

For each test-support implementation of `ActorContext` (likely `TestActorContext` in `packages/nexus-core/tests/Support/`), add a no-op or recording implementation:

```php
public ?Duration $lastReceiveTimeout = null;

public function setReceiveTimeout(?Duration $timeout): void
{
    $this->lastReceiveTimeout = $timeout;
}
```

### Step 4: Run all nexus-core tests to confirm nothing broke

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-core/tests/Unit
```

### Step 5: Commit

```bash
git add packages/nexus-core/src/Actor/ActorContext.php packages/nexus-core/tests/Support/
git commit --no-gpg-sign -m "feat(core): add ActorContext::setReceiveTimeout signature"
```

---

## Task 3: `ActorCell` timer integration

**Files:**
- Modify: `packages/nexus-core/src/Actor/ActorCell.php`
- Create: `packages/nexus-core/tests/Unit/Actor/ActorCellReceiveTimeoutTest.php`

### Step 1: Inspect `ActorCell`'s message-dispatch loop

```bash
grep -n "function dispatch\|function processMessage\|user message\|systemMessage" packages/nexus-core/src/Actor/ActorCell.php | head -20
```

Find the per-message hot-loop where user messages are processed. This is where the timer reset logic goes.

Also note: `Cancellable` is the return type of `Runtime::scheduleOnce()` per CLAUDE.md. The cell already has a `Runtime $runtime` field.

### Step 2: Write failing tests

`packages/nexus-core/tests/Unit/Actor/ActorCellReceiveTimeoutTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Actor;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Lifecycle\ReceiveTimeout;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Runtime\Duration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

final class ActorCellReceiveTimeoutTest extends TestCase
{
    #[Test]
    public function receiveTimeoutSignalFiresWhenIdle(): void
    {
        $runtime = new TestRuntime();
        $system = ActorSystem::create('test', $runtime);

        $signals = [];
        $behavior = Behavior::setup(static function (ActorContext $ctx) use (&$signals): Behavior {
            $ctx->setReceiveTimeout(Duration::millis(100));

            return Behavior::receive(static fn($c, object $msg) => Behavior::same())
                ->onSignal(static function ($c, object $signal) use (&$signals): Behavior {
                    if ($signal instanceof ReceiveTimeout) {
                        $signals[] = $signal;
                    }

                    return Behavior::same();
                });
        });

        $ref = $system->spawn(Props::fromBehavior($behavior), 'actor');
        $ref->tell(new stdClass());

        // Advance the TestRuntime's clock past the timeout
        $runtime->advance(Duration::millis(150));
        $runtime->drainScheduled();

        self::assertCount(1, $signals);
        self::assertTrue($signals[0]->configured->equals(Duration::millis(100)));
    }

    #[Test]
    public function userMessageResetsTimer(): void
    {
        $runtime = new TestRuntime();
        $system = ActorSystem::create('test', $runtime);

        $signals = [];
        $behavior = Behavior::setup(static function (ActorContext $ctx) use (&$signals): Behavior {
            $ctx->setReceiveTimeout(Duration::millis(100));

            return Behavior::receive(static fn($c, object $msg) => Behavior::same())
                ->onSignal(static function ($c, object $signal) use (&$signals): Behavior {
                    if ($signal instanceof ReceiveTimeout) {
                        $signals[] = $signal;
                    }

                    return Behavior::same();
                });
        });

        $ref = $system->spawn(Props::fromBehavior($behavior), 'actor');
        $ref->tell(new stdClass());

        // Send another message JUST before the timeout fires
        $runtime->advance(Duration::millis(80));
        $ref->tell(new stdClass());

        // Now advance past 100ms (total 130ms from start; only 50ms since
        // the last message). Timer should NOT have fired yet.
        $runtime->advance(Duration::millis(50));
        $runtime->drainScheduled();
        self::assertCount(0, $signals);

        // Advance another 100ms — now past the post-reset timeout
        $runtime->advance(Duration::millis(100));
        $runtime->drainScheduled();
        self::assertCount(1, $signals);
    }
}
```

### Step 3: Implement timer state + reset

In `ActorCell`:

```php
private ?Duration $receiveTimeout = null;
private ?Cancellable $receiveTimer = null;

public function setReceiveTimeout(?Duration $timeout): void
{
    $this->receiveTimeout = $timeout;

    $this->receiveTimer?->cancel();
    $this->receiveTimer = null;

    if ($timeout === null) {
        return;
    }

    $this->armReceiveTimer($timeout);
}

private function armReceiveTimer(Duration $timeout): void
{
    $this->receiveTimer = $this->runtime->scheduleOnce(
        $timeout,
        fn() => $this->onReceiveTimeout($timeout),
    );
}

private function onReceiveTimeout(Duration $configured): void
{
    if ($this->receiveTimer === null) {
        return;
    }
    $this->receiveTimer = null;

    // Dispatch as a signal via the same path PostStop/Terminated use.
    $this->deliverSignal(new ReceiveTimeout($configured));
}
```

`deliverSignal()` is whatever existing method routes lifecycle signals to the behavior's `onSignal` hook. If the cell does this via `Envelope::system($signal)`, use that.

In the user-message dispatch loop, after extracting the envelope but before invoking the behavior:
```php
if ($this->receiveTimeout !== null && $envelope->isUserMessage()) {
    $this->receiveTimer?->cancel();
    $this->armReceiveTimer($this->receiveTimeout);
}
```

Adapt to the actual envelope API — `isUserMessage()` may be `!$envelope->isSystemMessage()` or similar.

### Step 4: Verify + commit

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-core/tests/Unit/Actor/ActorCellReceiveTimeoutTest.php
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-core/tests/Unit
git add packages/nexus-core/src/Actor/ActorCell.php packages/nexus-core/tests/Unit/Actor/ActorCellReceiveTimeoutTest.php
git commit --no-gpg-sign -m "feat(core): wire ReceiveTimeout timer into ActorCell"
```

## STOP and escalate if

- `ActorCell`'s message dispatch loop is structured in a way that makes "every user message" hard to identify (e.g. messages and signals share a single channel without a type tag). Inspect carefully before adding the reset. If the cell already has a per-message hook (`onMessageProcessed`), use that.
- `TestRuntime::advance()` / `drainScheduled()` don't exist with those exact names. Read `packages/nexus-core/tests/Support/TestRuntime.php` and adapt the test.

---

## Task 4: `EntityBehaviorBuilder::withReceiveTimeout`

**Files:**
- Modify: `packages/nexus-doctrine-orm/src/Behavior/EntityBehaviorBuilder.php`
- Create: `packages/nexus-doctrine-orm/tests/Unit/Behavior/EntityBehaviorBuilderReceiveTimeoutTest.php`

### Step 1: Test

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Unit\Behavior;

use Monadial\Nexus\Doctrine\Orm\Behavior\EntityBehaviorBuilder;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityEffect;
use Monadial\Nexus\Runtime\Duration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(EntityBehaviorBuilder::class)]
final class EntityBehaviorBuilderReceiveTimeoutTest extends TestCase
{
    #[Test]
    public function withReceiveTimeoutReturnsNewInstance(): void
    {
        $base = new EntityBehaviorBuilder(
            entityClass: stdClass::class,
            id: 'k',
            commandHandler: static fn($ctx, object $msg, object $entity): EntityEffect => EntityEffect::same(),
        );

        $configured = $base->withReceiveTimeout(Duration::seconds(60));

        self::assertNotSame($base, $configured);
        self::assertNull($base->receiveTimeout);
        self::assertTrue($configured->receiveTimeout?->equals(Duration::seconds(60)));
    }
}
```

### Step 2: Add the field + setter

In `EntityBehaviorBuilder`:
```php
public ?Duration $receiveTimeout;
```

Add to constructor (last position, default `null`):
```php
public function __construct(
    // ... existing ...,
    ?Duration $receiveTimeout = null,
) {
    // ... existing ...
    $this->receiveTimeout = $receiveTimeout;
}
```

Add setter:
```php
public function withReceiveTimeout(Duration $timeout): self
{
    return new self(
        // ... all existing fields ...,
        receiveTimeout: $timeout,
    );
}
```

Update every other `with*` method to forward `$this->receiveTimeout` in its `new self(...)` call.

### Step 3: Verify + commit

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-doctrine-orm/tests/Unit/Behavior/EntityBehaviorBuilderReceiveTimeoutTest.php
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-doctrine-orm/tests/Unit
git add packages/nexus-doctrine-orm/src/Behavior/EntityBehaviorBuilder.php packages/nexus-doctrine-orm/tests/Unit/Behavior/EntityBehaviorBuilderReceiveTimeoutTest.php
git commit --no-gpg-sign -m "feat(doctrine-orm): add EntityBehaviorBuilder::withReceiveTimeout"
```

---

## Task 5: `EntityBehaviorRunner` ReceiveTimeout handling

**Files:**
- Modify: `packages/nexus-doctrine-orm/src/Behavior/EntityBehaviorRunner.php`
- Create: `packages/nexus-doctrine-orm/tests/Unit/Behavior/EntityBehaviorRunnerReceiveTimeoutTest.php`

### Step 1: Inspect current runner

```bash
cat packages/nexus-doctrine-orm/src/Behavior/EntityBehaviorRunner.php
```

Note the existing signal handler block.

### Step 2: Test

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Unit\Behavior;

// imports...

final class EntityBehaviorRunnerReceiveTimeoutTest extends TestCase
{
    #[Test]
    public function configuredTimeoutIsAppliedOnSetup(): void
    {
        // Use TestRuntime, spawn an EntityBehavior actor with a 100ms
        // timeout, send no messages, advance time, assert PostStop ran
        // (EM->close() and connection->close() called).
    }
}
```

### Step 3: Update runner

In the setup closure, after replay:
```php
if ($builder->receiveTimeout !== null) {
    $ctx->setReceiveTimeout($builder->receiveTimeout);
}
```

In the `onSignal` handler, add a `ReceiveTimeout` case BEFORE the `PostStop` case:
```php
if ($signal instanceof ReceiveTimeout) {
    return Behavior::stopped();   // PostStop will run and close the EM + connection
}
```

Don't manually close the EM here — let the existing `PostStop` handler do it.

### Step 4: Verify + commit

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-doctrine-orm/tests/Unit
git add packages/nexus-doctrine-orm/src/Behavior/EntityBehaviorRunner.php packages/nexus-doctrine-orm/tests/Unit/Behavior/EntityBehaviorRunnerReceiveTimeoutTest.php
git commit --no-gpg-sign -m "feat(doctrine-orm): EntityBehaviorRunner handles ReceiveTimeout → stopped"
```

---

## Task 6: `EntityRefFactory` self-cleaning + builder forwarding

**Files:**
- Modify: `packages/nexus-doctrine-orm/src/Behavior/EntityRefFactory.php`
- Modify: `packages/nexus-doctrine-orm/src/Behavior/EntityRefFactoryBuilder.php`
- Create: `packages/nexus-doctrine-orm/tests/Unit/Behavior/EntityRefFactoryEvictionTest.php`

### Step 1: Test

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Unit\Behavior;

// imports...

final class EntityRefFactoryEvictionTest extends TestCase
{
    #[Test]
    public function deadRefIsEvictedAndRespawned(): void
    {
        // Use a custom ActorSpawner that returns stub refs whose isAlive()
        // can be flipped on demand.
        // - of('k1') → ref_a (alive)
        // - of('k1') → ref_a (still alive, cached)
        // - flip ref_a to dead
        // - of('k1') → ref_b (new, alive) — different instance from ref_a
    }

    #[Test]
    public function receiveTimeoutForwardsThroughBuilder(): void
    {
        // The builder's withReceiveTimeout passes the duration through to
        // EntityBehavior::withReceiveTimeout when the actor is spawned.
        // Verify via a spy on the EntityBehavior builder or by sending a
        // message and observing passivation timing.
    }
}
```

### Step 2: Update `EntityRefFactory::of`

```php
public function of(mixed $id): ActorRef
{
    $name = self::deriveName($this->entityClass, $id);

    if (isset($this->cache[$name]) && $this->cache[$name]->isAlive()) {
        return $this->cache[$name];
    }

    unset($this->cache[$name]);

    $behavior = $this->buildBehavior($id);

    return $this->cache[$name] = $this->spawner->spawn(Props::fromBehavior($behavior), $name);
}

private function buildBehavior(mixed $id): Behavior
{
    $b = EntityBehavior::create($this->entityClass, $id, $this->commandHandler)
        ->withEntityManagerFactory($this->emFactory)
        ->withConnectionSource($this->connectionSource)
        ->withReplayPolicy($this->replayPolicy);

    if ($this->receiveTimeout !== null) {
        $b = $b->withReceiveTimeout($this->receiveTimeout);
    }

    return $b->toBehavior();
}
```

Add field:
```php
private readonly ?Duration $receiveTimeout;
```

Update `instantiate()` static factory to take an optional `?Duration $receiveTimeout`.

### Step 3: Update `EntityRefFactoryBuilder`

Add:
```php
private ?Duration $receiveTimeout = null;

public function withReceiveTimeout(Duration $timeout): self
{
    $this->receiveTimeout = $timeout;

    return $this;
}
```

Update `build()` to pass `$this->receiveTimeout` to `EntityRefFactory::instantiate()`.

### Step 4: Verify + commit

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-doctrine-orm/tests/Unit
git add packages/nexus-doctrine-orm/src/Behavior/EntityRefFactory.php packages/nexus-doctrine-orm/src/Behavior/EntityRefFactoryBuilder.php packages/nexus-doctrine-orm/tests/Unit/Behavior/EntityRefFactoryEvictionTest.php
git commit --no-gpg-sign -m "feat(doctrine-orm): EntityRefFactory self-cleans + forwards receiveTimeout"
```

---

## Task 7: Fiber integration test

**Files:**
- Create: `tests/Integration/Fiber/ReceiveTimeoutPassivationTest.php`

### Step 1: Test

Boot a `FiberRuntime` + `ActorSystem`. Spawn a behavior with `setReceiveTimeout(Duration::millis(100))`. Send one message, then call `scheduleOnce(150ms, fn() => $assertActorDead)` then `scheduleOnce(300ms, fn() => $system->shutdown(…))`. Run. Assert the actor was alive after the message, dead after 150ms.

(Real `FiberRuntime` instead of `TestRuntime` — this is the integration test.)

### Step 2: Add testsuite to phpunit.xml

If `integration-fiber` already exists (it does — see existing entries), the test will be picked up automatically. No phpunit.xml change.

### Step 3: Run + commit

```bash
docker compose exec -T php-fiber vendor/bin/phpunit tests/Integration/Fiber/ReceiveTimeoutPassivationTest.php
git add tests/Integration/Fiber/ReceiveTimeoutPassivationTest.php
git commit --no-gpg-sign -m "test(core): Fiber integration test for ReceiveTimeout passivation"
```

---

## Task 8: EntityBehavior passivation integration test

**Files:**
- Create: `tests/Integration/Doctrine/Orm/EntityBehavior/PassivationTest.php`

### Step 1: Test

End-to-end:
1. Bootstrap schema + tempfile SQLite (mirroring HappyPathTest.php pattern).
2. Spawn an `EntityBehavior` actor via `EntityRefFactory::of('c-1')` with `withReceiveTimeout(Duration::millis(100))`.
3. Send `Add(5)`, verify persistence.
4. Schedule `assertActorDead` at 200ms (well past timeout).
5. Schedule a fresh `of('c-1')->tell(new Add(7))` at 250ms — verify it rehydrates from DB (sees `value=5`) and persists `value=12`.
6. Shutdown at 500ms. Verify final DB row is `value=12`.

### Step 2: Run + commit

```bash
docker compose exec -T php-fiber vendor/bin/phpunit tests/Integration/Doctrine/Orm/EntityBehavior/PassivationTest.php
git add tests/Integration/Doctrine/Orm/EntityBehavior/PassivationTest.php
git commit --no-gpg-sign -m "test(doctrine-orm): EntityBehavior passivation + rehydration integration"
```

---

## Task 9: Documentation update

**Files:**
- Modify: `website/docs/core-concepts/lifecycle.md` — add `ReceiveTimeout` to the signal list
- Modify: `website/docs/doctrine/entity-behavior.md` — add a passivation section
- Modify: `website/docs/packages/doctrine-orm.md` — list `withReceiveTimeout` in the builder method table

### Step 1: Update `lifecycle.md`

Add a section after `Terminated`:

```markdown
### `ReceiveTimeout`

Fired by the actor cell when no user message arrives within a configured
duration. Opt-in via `$ctx->setReceiveTimeout(Duration::seconds(N))` from
inside `Behavior::setup`. Return `Behavior::stopped()` from the signal
handler to passivate the actor — its `PostStop` runs and resources are
released.

System messages (Watch/Unwatch/PoisonPill) do **not** reset the timer.
Only user messages do.
```

### Step 2: Update `entity-behavior.md`

Add a "Passivation" section after "Dedicated, non-pooled EM":

```markdown
## Passivation

`EntityBehavior` actors hold their EM and Connection for their whole
lifetime. For hot entities that's fine; for the long tail it's expensive.
Opt into passivation via `withReceiveTimeout`:

\`\`\`php
$behavior = EntityBehavior::create(...)
    ->withEntityManagerFactory($emFactory)
    ->withConnectionSource($connSource)
    ->withReceiveTimeout(Duration::seconds(120))   // passivate after 2min idle
    ->toBehavior();
\`\`\`

After 120s without messages, the actor self-terminates. The runner's
`PostStop` handler closes the EM and Connection. The next call to
`EntityRefFactory::of($id)` notices the cached ref is dead, spawns a
fresh actor, reloads the entity from DB, and processes the incoming
message — transparent to the caller.

The rehydration window (between passivation and the next `of($id)`) sends
any in-flight messages to dead letters. For most write paths this is
acceptable — clients retry. For high-stakes commands, send via `ask()`
and let the per-message timeout surface failures.
```

### Step 3: Update `packages/doctrine-orm.md`

In the `EntityBehaviorBuilder` row of the class table, append to the description:

> ... `withReceiveTimeout`, ...

### Step 4: Commit

```bash
git add website/docs/
git commit --no-gpg-sign -m "docs: ReceiveTimeout + EntityBehavior passivation"
```

---

## Task 10: Final repo-wide gate

- [ ] **Step 1: Unit suites**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit --testsuite=unit
docker compose exec -T php-swoole vendor/bin/phpunit --testsuite=unit-swoole
docker compose exec -T php-fiber vendor/bin/phpunit --testsuite=psalm
```

All green.

- [ ] **Step 2: Linters**

```bash
docker compose exec -T php-fiber vendor/bin/phpcs
docker compose exec -T php-fiber vendor/bin/php-cs-fixer fix --dry-run
docker compose exec -T php vendor/bin/psalm --no-cache
docker compose exec -T php php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/deptrac analyze
```

All green.

- [ ] **Step 3: Integration**

```bash
make test-fiber
make test-doctrine
```

- [ ] **Step 4: Branch state**

```bash
git status
git log --oneline feat/nexus-http..HEAD | wc -l
```

Working tree clean (except the two pre-existing unstaged files).

- [ ] **Step 5: Push (with user approval)**

Ask the user before pushing.

---

## Self-review checklist

- [ ] No `TBD` / `TODO` / `FIXME` strings in this plan: `grep -E 'TBD|TODO|FIXME' docs/superpowers/plans/2026-06-17-receive-timeout-passivation.md`
- [ ] Every spec section (signal, ActorContext method, ActorCell wiring, EntityBehaviorBuilder, runner, EntityRefFactory eviction, integration tests, docs) maps to a task.
- [ ] Method names consistent: `setReceiveTimeout`, `withReceiveTimeout`, `ReceiveTimeout` (class), `$receiveTimeout` (field).
- [ ] Commit prefixes: `feat(core):`, `feat(doctrine-orm):`, `test(...):`, `docs:`.
- [ ] EventSourcedBehavior / DurableStateBehavior wiring is deferred (mentioned as out-of-scope in spec).
- [ ] Stash-during-passivation queue is deferred.

---

## Follow-ups (out of scope, mentioned for tracking)

- `EventSourcedBehavior::withReceiveTimeout(Duration)` — same one-line wiring as `EntityBehavior`.
- `DurableStateBehavior::withReceiveTimeout(Duration)` — same.
- Stash-and-replay during passivation rehydration — directory-level proxy holds the messages.
- Backpressure on rehydration storms — rate-limit `of($id)` spawns under burst load.
