# Actor System Telemetry Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add pull-based observability to Nexus exposing actor hierarchy snapshots and Swoole runtime stats via HTTP `/status` (JSON) and `/metrics` (Prometheus text) endpoints.

**Architecture:** `ActorCell` and `ActorSystem` gain `snapshot()` methods (pure reads, no side effects). In WorkerPool mode, `WorkerTelemetryPublisher` runs a background coroutine per worker writing snapshots to a shared `Thread\Map` every 5 seconds. `WorkerPoolTelemetryServer` (main thread) reads and aggregates all workers into a single HTTP endpoint. See design doc: `docs/plans/2026-03-06-actor-telemetry-design.md`.

**Tech Stack:** PHP 8.5, Swoole 6.0, `Swoole\Coroutine::stats()`, `Swoole\Thread\Map`, `Swoole\Coroutine\Http\Server`, PHPUnit 11

**Working directory for all commands:** `.worktrees/feat/symfony-integration`

---

## Task 1: `ActorSnapshot` and `ActorSystemSnapshot` value objects

**Files:**
- Create: `packages/nexus-core/src/Actor/Telemetry/ActorSnapshot.php`
- Create: `packages/nexus-core/src/Actor/Telemetry/ActorSystemSnapshot.php`
- Test: `packages/nexus-core/tests/Unit/Actor/Telemetry/ActorSnapshotTest.php`

**Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Actor\Telemetry;

use Monadial\Nexus\Core\Actor\Telemetry\ActorSnapshot;
use Monadial\Nexus\Core\Actor\Telemetry\ActorSystemSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActorSnapshot::class)]
#[CoversClass(ActorSystemSnapshot::class)]
final class ActorSnapshotTest extends TestCase
{
    #[Test]
    public function actor_snapshot_exposes_all_fields(): void
    {
        $child = new ActorSnapshot('/user/orders/child', true, 0, PHP_INT_MAX, false, []);
        $snapshot = new ActorSnapshot('/user/orders', true, 3, 1000, true, [$child]);

        self::assertSame('/user/orders', $snapshot->path);
        self::assertTrue($snapshot->alive);
        self::assertSame(3, $snapshot->mailboxDepth);
        self::assertSame(1000, $snapshot->mailboxCapacity);
        self::assertTrue($snapshot->mailboxBounded);
        self::assertCount(1, $snapshot->children);
        self::assertSame('/user/orders/child', $snapshot->children[0]->path);
    }

    #[Test]
    public function actor_snapshot_to_array_serializes_recursively(): void
    {
        $child = new ActorSnapshot('/user/orders/child', true, 0, PHP_INT_MAX, false, []);
        $snapshot = new ActorSnapshot('/user/orders', true, 3, 1000, true, [$child]);

        $array = $snapshot->toArray();

        self::assertSame('/user/orders', $array['path']);
        self::assertSame(3, $array['mailbox_depth']);
        self::assertCount(1, $array['children']);
        self::assertSame('/user/orders/child', $array['children'][0]['path']);
    }

    #[Test]
    public function actor_system_snapshot_exposes_all_fields(): void
    {
        $actor = new ActorSnapshot('/user/orders', true, 0, PHP_INT_MAX, false, []);
        $snapshot = new ActorSystemSnapshot('my-system', '01HXYZ', true, [$actor], 2);

        self::assertSame('my-system', $snapshot->systemName);
        self::assertSame('01HXYZ', $snapshot->writerId);
        self::assertTrue($snapshot->isRunning);
        self::assertCount(1, $snapshot->actors);
        self::assertSame(2, $snapshot->deadLettersCount);
    }

    #[Test]
    public function actor_system_snapshot_to_array_serializes(): void
    {
        $actor = new ActorSnapshot('/user/orders', true, 0, PHP_INT_MAX, false, []);
        $snapshot = new ActorSystemSnapshot('my-system', '01HXYZ', true, [$actor], 0);

        $array = $snapshot->toArray();

        self::assertSame('my-system', $array['name']);
        self::assertSame('01HXYZ', $array['writer_id']);
        self::assertTrue($array['is_running']);
        self::assertCount(1, $array['actors']);
        self::assertSame(0, $array['dead_letters_count']);
    }
}
```

**Step 2: Run to confirm failure**

```bash
docker compose exec php vendor/bin/phpunit \
  packages/nexus-core/tests/Unit/Actor/Telemetry/ActorSnapshotTest.php
```

Expected: FAIL — class not found.

**Step 3: Create `ActorSnapshot`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor\Telemetry;

/**
 * @psalm-api
 *
 * Immutable snapshot of a single actor's observable state.
 * Children are included recursively, reflecting the real actor hierarchy.
 */
final readonly class ActorSnapshot
{
    /**
     * @param ActorSnapshot[] $children
     */
    public function __construct(
        public string $path,
        public bool $alive,
        public int $mailboxDepth,
        public int $mailboxCapacity,
        public bool $mailboxBounded,
        public array $children,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'alive' => $this->alive,
            'children' => array_values(array_map(
                static fn(self $c): array => $c->toArray(),
                $this->children,
            )),
            'mailbox_bounded' => $this->mailboxBounded,
            'mailbox_capacity' => $this->mailboxCapacity,
            'mailbox_depth' => $this->mailboxDepth,
            'path' => $this->path,
        ];
    }
}
```

**Step 4: Create `ActorSystemSnapshot`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor\Telemetry;

/**
 * @psalm-api
 *
 * Immutable snapshot of an ActorSystem's observable state.
 */
final readonly class ActorSystemSnapshot
{
    /**
     * @param ActorSnapshot[] $actors
     */
    public function __construct(
        public string $systemName,
        public string $writerId,
        public bool $isRunning,
        public array $actors,
        public int $deadLettersCount,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'actors' => array_values(array_map(
                static fn(ActorSnapshot $a): array => $a->toArray(),
                $this->actors,
            )),
            'dead_letters_count' => $this->deadLettersCount,
            'is_running' => $this->isRunning,
            'name' => $this->systemName,
            'writer_id' => $this->writerId,
        ];
    }
}
```

**Step 5: Run to confirm passing**

```bash
docker compose exec php vendor/bin/phpunit \
  packages/nexus-core/tests/Unit/Actor/Telemetry/ActorSnapshotTest.php
```

Expected: PASS (4 tests).

**Step 6: Commit**

```bash
cd .worktrees/feat/symfony-integration
git add packages/nexus-core/src/Actor/Telemetry/ \
        packages/nexus-core/tests/Unit/Actor/Telemetry/
git commit -m "feat(telemetry): add ActorSnapshot and ActorSystemSnapshot value objects"
```

---

## Task 2: `ActorCell` — mailbox config + child cell tracking + `snapshot()`

**Files:**
- Modify: `packages/nexus-core/src/Actor/ActorCell.php`
- Test: `packages/nexus-core/tests/Unit/Actor/ActorCellSnapshotTest.php`

**Context:** `ActorCell` currently tracks children only as `ActorRef[]` in `$childrenMap`. To produce a recursive snapshot, we need to also retain the `ActorCell` references for children and the `MailboxConfig` for each cell.

**Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Actor;

use Monadial\Nexus\Core\Actor\ActorCell;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\DeadLetterRef;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Actor\Telemetry\ActorSnapshot;
use Monadial\Nexus\Core\Tests\Support\TestClock;
use Monadial\Nexus\Core\Tests\Support\TestMailbox;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Core\Supervision\SupervisionStrategy;
use Monadial\Nexus\Runtime\Mailbox\MailboxConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(ActorCell::class)]
final class ActorCellSnapshotTest extends TestCase
{
    #[Test]
    public function snapshot_returns_actor_snapshot_with_mailbox_stats(): void
    {
        $runtime = new TestRuntime();
        $clock = new TestClock();
        $mailbox = new TestMailbox();
        $config = MailboxConfig::bounded(500);

        $cell = new ActorCell(
            Behavior::receive(static fn($ctx, $msg) => Behavior::same()),
            ActorPath::fromString('/user/orders'),
            $mailbox,
            $config,
            $runtime,
            null,
            SupervisionStrategy::oneForOne(),
            $clock,
            new NullLogger(),
            new DeadLetterRef(),
        );
        $cell->start();

        $snapshot = $cell->snapshot();

        self::assertInstanceOf(ActorSnapshot::class, $snapshot);
        self::assertSame('/user/orders', $snapshot->path);
        self::assertTrue($snapshot->alive);
        self::assertSame(0, $snapshot->mailboxDepth);
        self::assertSame(500, $snapshot->mailboxCapacity);
        self::assertTrue($snapshot->mailboxBounded);
        self::assertSame([], $snapshot->children);
    }

    #[Test]
    public function snapshot_includes_children_recursively(): void
    {
        $runtime = new TestRuntime();
        $clock = new TestClock();
        $mailbox = new TestMailbox();
        $config = MailboxConfig::unbounded();

        $cell = new ActorCell(
            Behavior::receive(static fn($ctx, $msg) => Behavior::same()),
            ActorPath::fromString('/user/orders'),
            $mailbox,
            $config,
            $runtime,
            null,
            SupervisionStrategy::oneForOne(),
            $clock,
            new NullLogger(),
            new DeadLetterRef(),
        );
        $cell->start();

        $cell->spawn(
            Props::fromBehavior(Behavior::receive(static fn($ctx, $msg) => Behavior::same())),
            'processor',
        );

        $snapshot = $cell->snapshot();

        self::assertCount(1, $snapshot->children);
        self::assertSame('/user/orders/processor', $snapshot->children[0]->path);
    }
}
```

**Step 2: Run to confirm failure**

```bash
docker compose exec php vendor/bin/phpunit \
  packages/nexus-core/tests/Unit/Actor/ActorCellSnapshotTest.php
```

Expected: FAIL — `ActorCell` constructor argument count mismatch (no `MailboxConfig` param yet, no `snapshot()` method).

**Step 3: Modify `ActorCell`**

Add `MailboxConfig $mailboxConfig` as the 4th constructor parameter (after `$mailbox`), add `$childCells` tracking, and add `snapshot()`. Do all three changes together since they are coupled.

In `packages/nexus-core/src/Actor/ActorCell.php`:

Add import at top (in class, function, const order — alphabetically):
```php
use Monadial\Nexus\Core\Actor\Telemetry\ActorSnapshot;
use Monadial\Nexus\Runtime\Mailbox\MailboxConfig;
```

Add property after `$stashBuffer`:
```php
/** @var array<string, self<object>> */
private array $childCells = [];
```

Modify constructor signature — insert `MailboxConfig` as 4th param:
```php
public function __construct(
    Behavior $behavior,
    private readonly ActorPath $actorPath,
    private readonly Mailbox $mailbox,
    private readonly MailboxConfig $mailboxConfig,
    private readonly Runtime $runtime,
    private readonly ?ActorRef $parentRef,
    private readonly SupervisionStrategy $supervision,
    private readonly ClockInterface $clock,
    private readonly LoggerInterface $logger,
    private readonly DeadLetterRef $deadLetters,
)
```

In `spawn()`, after `$childCell->start()` and before `$this->spawnMessageLoop(...)`:
```php
/** @var self<object> $childCell */
$this->childCells[$name] = $childCell;
```

The child `ActorCell` construction in `spawn()` also needs `$props->mailbox` passed. Modify the `new self(...)` call to add `$props->mailbox` as 4th argument:
```php
$childCell = new self(
    $props->behavior,
    $childPath,
    $childMailbox,
    $props->mailbox,        // <-- add this
    $this->runtime,
    $parentRef,
    $typedSupervision,
    $this->clock,
    $this->logger,
    $this->deadLetters,
);
```

Add `snapshot()` method at the end of the public section (before `// ---- Internal message handling ----`):
```php
/**
 * Returns an immutable snapshot of this actor's current observable state,
 * including its children recursively.
 */
public function snapshot(): ActorSnapshot
{
    $children = [];

    foreach ($this->childCells as $childCell) {
        $children[] = $childCell->snapshot();
    }

    return new ActorSnapshot(
        path: (string) $this->actorPath,
        alive: $this->isAlive(),
        mailboxDepth: $this->mailbox->count(),
        mailboxCapacity: $this->mailboxConfig->capacity,
        mailboxBounded: $this->mailboxConfig->bounded,
        children: $children,
    );
}
```

**Step 4: Fix all `ActorCell` construction call sites**

The existing constructor is called in two places: `ActorSystem::createActorCell()` and `ActorCell::spawn()` (already handled above). Check for any test files that construct `ActorCell` directly and add the `MailboxConfig` argument there too.

Search:
```bash
cd .worktrees/feat/symfony-integration
grep -rn "new ActorCell(" packages/ tests/ --include="*.php"
```

For each match, insert `MailboxConfig::unbounded(),` (or appropriate config) as the 4th argument. In `ActorSystem::createActorCell()`, pass `$props->mailbox`:
```php
$childCell = new ActorCell(
    $props->behavior,
    $childPath,
    $childMailbox,
    $props->mailbox,        // <-- add this
    $this->runtime,
    null,
    $typedSupervision,
    $this->clock,
    $this->logger,
    $this->deadLetters,
);
```

**Step 5: Run the tests**

```bash
docker compose exec php vendor/bin/phpunit \
  packages/nexus-core/tests/Unit/Actor/ActorCellSnapshotTest.php
```

Expected: PASS (2 tests).

Run full unit suite to check nothing broke:
```bash
docker compose exec php vendor/bin/phpunit --testsuite=unit
```

Expected: all passing.

**Step 6: Commit**

```bash
git add packages/nexus-core/src/Actor/ActorCell.php \
        packages/nexus-core/tests/Unit/Actor/ActorCellSnapshotTest.php
git commit -m "feat(telemetry): ActorCell tracks child cells and exposes snapshot()"
```

---

## Task 3: `ActorSystem::snapshot()`

**Files:**
- Modify: `packages/nexus-core/src/Actor/ActorSystem.php`
- Modify: `packages/nexus-core/tests/Unit/Actor/ActorSystemTest.php` (add test method)

**Step 1: Write the failing test**

Add this test method to the existing `ActorSystemTest`:

```php
#[Test]
public function snapshot_returns_actor_hierarchy(): void
{
    $system = ActorSystem::create('snap-test', $this->runtime);
    $props = Props::fromBehavior(Behavior::receive(static fn($ctx, $msg) => Behavior::same()));

    $system->spawn($props, 'orders');
    $system->spawn($props, 'payments');

    $snapshot = $system->snapshot();

    self::assertSame('snap-test', $snapshot->systemName);
    self::assertSame(2, count($snapshot->actors));

    $paths = array_map(static fn($a) => $a->path, $snapshot->actors);
    self::assertContains('/user/orders', $paths);
    self::assertContains('/user/payments', $paths);
    self::assertSame(0, $snapshot->deadLettersCount);
}
```

**Step 2: Run to confirm failure**

```bash
docker compose exec php vendor/bin/phpunit \
  --filter=snapshot_returns_actor_hierarchy \
  packages/nexus-core/tests/Unit/Actor/ActorSystemTest.php
```

Expected: FAIL — `ActorSystem::snapshot()` does not exist.

**Step 3: Modify `ActorSystem`**

Add import:
```php
use Monadial\Nexus\Core\Actor\Telemetry\ActorSystemSnapshot;
```

Add property after `$children`:
```php
/** @var array<string, ActorCell<object>> */
private array $childCells = [];
```

Modify `createActorCell()` — store the cell:
```php
private function createActorCell(Props $props, string $name): ActorRef
{
    $childPath = $this->userGuardianPath->child($name);
    /** @var Mailbox<Envelope> $childMailbox */
    $childMailbox = $this->runtime->createMailbox($props->mailbox);

    $typedSupervision = $props->supervision ?? SupervisionStrategy::oneForOne();

    $childCell = new ActorCell(
        $props->behavior,
        $childPath,
        $childMailbox,
        $props->mailbox,        // <-- pass MailboxConfig
        $this->runtime,
        null,
        $typedSupervision,
        $this->clock,
        $this->logger,
        $this->deadLetters,
    );
    $childCell->start();

    $this->spawnMessageLoop($childCell, $childMailbox);

    return $childCell->self();
}
```

Then in `spawn()` and `spawnAnonymous()`, store the cell BEFORE returning:

```php
public function spawn(Props $props, string $name): ActorRef
{
    if (isset($this->children[$name])) {
        throw new ActorNameExistsException($this->userGuardianPath, $name);
    }

    $ref = $this->createActorCell($props, $name);
    $this->children[$name] = $ref;

    return $ref;
}
```

Wait — we need to return the cell from `createActorCell()`. Change `createActorCell()` to return `ActorCell` and extract the ref from it:

```php
/**
 * @template T of object
 * @param Props<T> $props
 * @return ActorCell<T>
 * @throws ActorInitializationException
 */
private function createActorCell(Props $props, string $name): ActorCell
{
    $childPath = $this->userGuardianPath->child($name);
    /** @var Mailbox<Envelope> $childMailbox */
    $childMailbox = $this->runtime->createMailbox($props->mailbox);

    $typedSupervision = $props->supervision ?? SupervisionStrategy::oneForOne();

    /** @var ActorCell<T> $childCell */
    $childCell = new ActorCell(
        $props->behavior,
        $childPath,
        $childMailbox,
        $props->mailbox,
        $this->runtime,
        null,
        $typedSupervision,
        $this->clock,
        $this->logger,
        $this->deadLetters,
    );
    $childCell->start();

    $this->spawnMessageLoop($childCell, $childMailbox);

    return $childCell;
}
```

Then `spawn()` and `spawnAnonymous()`:

```php
public function spawn(Props $props, string $name): ActorRef
{
    if (isset($this->children[$name])) {
        throw new ActorNameExistsException($this->userGuardianPath, $name);
    }

    $cell = $this->createActorCell($props, $name);
    $this->children[$name] = $cell->self();
    $this->childCells[$name] = $cell;

    return $cell->self();
}

public function spawnAnonymous(Props $props): ActorRef
{
    $name = 'auto-' . $this->anonymousCounter++;
    $cell = $this->createActorCell($props, $name);
    $this->children[$name] = $cell->self();
    $this->childCells[$name] = $cell;

    return $cell->self();
}
```

Also update `spawnMessageLoop()` signature — it still takes `ActorCell` and `Mailbox` but now `createActorCell()` doesn't call it directly. Move the call into `spawn()`/`spawnAnonymous()`. Actually keep `spawnMessageLoop()` called inside `createActorCell()` and have `createActorCell()` return the cell after spawning the loop.

Add `snapshot()` method:

```php
/**
 * Returns an immutable snapshot of the actor system's current state,
 * including the full recursive actor hierarchy.
 */
public function snapshot(): ActorSystemSnapshot
{
    $actors = [];

    foreach ($this->childCells as $cell) {
        $actors[] = $cell->snapshot();
    }

    return new ActorSystemSnapshot(
        systemName: $this->systemName,
        writerId: (string) $this->writerId,
        isRunning: $this->isRunning(),
        actors: $actors,
        deadLettersCount: count($this->deadLetters->captured()),
    );
}
```

**Step 4: Run tests**

```bash
docker compose exec php vendor/bin/phpunit \
  packages/nexus-core/tests/Unit/Actor/ActorSystemTest.php
```

Expected: all passing including the new test.

```bash
docker compose exec php vendor/bin/phpunit --testsuite=unit
```

Expected: all passing.

**Step 5: Commit**

```bash
git add packages/nexus-core/src/Actor/ActorSystem.php \
        packages/nexus-core/tests/Unit/Actor/ActorSystemTest.php
git commit -m "feat(telemetry): ActorSystem tracks child cells and exposes snapshot()"
```

---

## Task 4: `SwooleRuntimeSnapshot` and `SwooleRuntime::snapshot()`

**Files:**
- Create: `packages/nexus-runtime-swoole/src/Telemetry/SwooleRuntimeSnapshot.php`
- Modify: `packages/nexus-runtime-swoole/src/SwooleRuntime.php`
- Test: `packages/nexus-runtime-swoole/tests/Unit/Telemetry/SwooleRuntimeSnapshotTest.php`

**Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Swoole\Tests\Unit\Telemetry;

use Monadial\Nexus\Runtime\Swoole\Telemetry\SwooleRuntimeSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SwooleRuntimeSnapshot::class)]
final class SwooleRuntimeSnapshotTest extends TestCase
{
    #[Test]
    public function snapshot_exposes_all_fields(): void
    {
        $snapshot = new SwooleRuntimeSnapshot(
            coroutineNum: 12,
            coroutinePeakNum: 20,
            activeTimers: 4,
            memoryBytes: 8_388_608,
            memoryPeakBytes: 12_582_912,
        );

        self::assertSame(12, $snapshot->coroutineNum);
        self::assertSame(20, $snapshot->coroutinePeakNum);
        self::assertSame(4, $snapshot->activeTimers);
        self::assertSame(8_388_608, $snapshot->memoryBytes);
        self::assertSame(12_582_912, $snapshot->memoryPeakBytes);
    }

    #[Test]
    public function to_array_produces_snake_case_keys(): void
    {
        $snapshot = new SwooleRuntimeSnapshot(12, 20, 4, 8_388_608, 12_582_912);

        $array = $snapshot->toArray();

        self::assertSame(12, $array['coroutine_num']);
        self::assertSame(20, $array['coroutine_peak_num']);
        self::assertSame(4, $array['active_timers']);
        self::assertSame(8_388_608, $array['memory_bytes']);
        self::assertSame(12_582_912, $array['memory_peak_bytes']);
    }
}
```

**Step 2: Run to confirm failure**

```bash
docker compose exec php-swoole vendor/bin/phpunit \
  packages/nexus-runtime-swoole/tests/Unit/Telemetry/SwooleRuntimeSnapshotTest.php
```

Expected: FAIL — class not found.

**Step 3: Create `SwooleRuntimeSnapshot`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Swoole\Telemetry;

/**
 * @psalm-api
 *
 * Immutable snapshot of SwooleRuntime's observable state.
 */
final readonly class SwooleRuntimeSnapshot
{
    public function __construct(
        public int $coroutineNum,
        public int $coroutinePeakNum,
        public int $activeTimers,
        public int $memoryBytes,
        public int $memoryPeakBytes,
    ) {}

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'active_timers' => $this->activeTimers,
            'coroutine_num' => $this->coroutineNum,
            'coroutine_peak_num' => $this->coroutinePeakNum,
            'memory_bytes' => $this->memoryBytes,
            'memory_peak_bytes' => $this->memoryPeakBytes,
        ];
    }
}
```

**Step 4: Add `snapshot()` to `SwooleRuntime`**

Add import:
```php
use Monadial\Nexus\Runtime\Swoole\Telemetry\SwooleRuntimeSnapshot;
use Swoole\Coroutine;
```

(`Swoole\Coroutine` is already imported — just add `SwooleRuntimeSnapshot`.)

Add method after `isRunning()`:

```php
/**
 * Returns a point-in-time snapshot of Swoole runtime statistics.
 */
public function snapshot(): SwooleRuntimeSnapshot
{
    /** @var array<string, int> $stats */
    $stats = Coroutine::stats();

    return new SwooleRuntimeSnapshot(
        coroutineNum: $stats['coroutine_num'] ?? 0,
        coroutinePeakNum: $stats['coroutine_peak_num'] ?? 0,
        activeTimers: count($this->timerIds),
        memoryBytes: memory_get_usage(),
        memoryPeakBytes: memory_get_peak_usage(),
    );
}
```

**Step 5: Run tests**

```bash
docker compose exec php-swoole vendor/bin/phpunit \
  packages/nexus-runtime-swoole/tests/Unit/Telemetry/SwooleRuntimeSnapshotTest.php
```

Expected: PASS (2 tests).

```bash
docker compose exec php-swoole vendor/bin/phpunit --testsuite=unit
```

Expected: all passing.

**Step 6: Commit**

```bash
git add packages/nexus-runtime-swoole/src/Telemetry/SwooleRuntimeSnapshot.php \
        packages/nexus-runtime-swoole/src/SwooleRuntime.php \
        packages/nexus-runtime-swoole/tests/Unit/Telemetry/SwooleRuntimeSnapshotTest.php
git commit -m "feat(telemetry): SwooleRuntimeSnapshot and SwooleRuntime::snapshot()"
```

---

## Task 5: `TelemetryServer` (standalone Swoole HTTP server)

**Files:**
- Create: `packages/nexus-runtime-swoole/src/Telemetry/TelemetryServer.php`
- Test: `tests/Integration/Swoole/TelemetryServerTest.php`

**Context:** The standalone `TelemetryServer` wraps a `Swoole\Coroutine\Http\Server` and serves `/status` (JSON) and `/metrics` (Prometheus text) using live `ActorSystem::snapshot()` and `SwooleRuntime::snapshot()` calls. It must be started inside a coroutine context (`Co\run()`).

**Step 1: Write the integration test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Swoole;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use Monadial\Nexus\Runtime\Swoole\Telemetry\TelemetryServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;

use function Swoole\Coroutine\run;

#[CoversClass(TelemetryServer::class)]
final class TelemetryServerTest extends TestCase
{
    #[Test]
    public function status_endpoint_returns_json_with_actor_hierarchy(): void
    {
        $captured = [];

        run(static function () use (&$captured): void {
            $runtime = new SwooleRuntime();
            $system = ActorSystem::create('telemetry-test', $runtime);

            $system->spawn(
                Props::fromBehavior(Behavior::receive(static fn($ctx, $msg) => Behavior::same())),
                'orders',
            );

            $server = new TelemetryServer($system, $runtime, port: 19502);
            $server->start();

            Coroutine::sleep(0.05);

            $client = new Client('127.0.0.1', 19502);
            $client->get('/status');
            $captured['status'] = $client->statusCode;
            $captured['body'] = json_decode($client->body, true);
            $client->close();

            $system->shutdown(Duration::seconds(1));
        });

        self::assertSame(200, $captured['status']);
        self::assertSame('standalone', $captured['body']['mode']);
        self::assertSame('telemetry-test', $captured['body']['system']['name']);
        self::assertCount(1, $captured['body']['system']['actors']);
        self::assertSame('/user/orders', $captured['body']['system']['actors'][0]['path']);
    }

    #[Test]
    public function metrics_endpoint_returns_prometheus_text(): void
    {
        $captured = [];

        run(static function () use (&$captured): void {
            $runtime = new SwooleRuntime();
            $system = ActorSystem::create('prom-test', $runtime);

            $system->spawn(
                Props::fromBehavior(Behavior::receive(static fn($ctx, $msg) => Behavior::same())),
                'payments',
            );

            $server = new TelemetryServer($system, $runtime, port: 19503);
            $server->start();

            Coroutine::sleep(0.05);

            $client = new Client('127.0.0.1', 19503);
            $client->get('/metrics');
            $captured['status'] = $client->statusCode;
            $captured['body'] = $client->body;
            $client->close();

            $system->shutdown(Duration::seconds(1));
        });

        self::assertSame(200, $captured['status']);
        self::assertStringContainsString('nexus_actor_mailbox_depth', $captured['body']);
        self::assertStringContainsString('/user/payments', $captured['body']);
        self::assertStringContainsString('nexus_coroutine_num', $captured['body']);
    }
}
```

**Step 2: Run to confirm failure**

```bash
docker compose exec php-swoole vendor/bin/phpunit \
  tests/Integration/Swoole/TelemetryServerTest.php
```

Expected: FAIL — `TelemetryServer` not found.

**Step 3: Create `TelemetryServer`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Swoole\Telemetry;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Telemetry\ActorSnapshot;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;

/**
 * @psalm-api
 *
 * Standalone Swoole HTTP server exposing actor system telemetry.
 *
 * Must be started inside a coroutine context (Co\run()).
 * Calls system->snapshot() and runtime->snapshot() live on each request
 * — zero background overhead.
 */
final class TelemetryServer
{
    public function __construct(
        private readonly ActorSystem $system,
        private readonly SwooleRuntime $runtime,
        private readonly string $host = '0.0.0.0',
        private readonly int $port = 9502,
    ) {}

    /**
     * Start the HTTP server in a new coroutine. Non-blocking.
     */
    public function start(): void
    {
        $server = new Server($this->host, $this->port, false, true);

        $server->handle('/status', function (Request $req, Response $res): void {
            $systemSnapshot = $this->system->snapshot();
            $runtimeSnapshot = $this->runtime->snapshot();

            $body = json_encode([
                'mode' => 'standalone',
                'runtime' => $runtimeSnapshot->toArray(),
                'system' => $systemSnapshot->toArray(),
            ], JSON_THROW_ON_ERROR);

            $res->header('Content-Type', 'application/json');
            $res->end($body);
        });

        $server->handle('/metrics', function (Request $req, Response $res): void {
            $systemSnapshot = $this->system->snapshot();
            $runtimeSnapshot = $this->runtime->snapshot();

            $lines = [];
            $systemName = $systemSnapshot->systemName;

            // Actor metrics (recursive)
            $lines[] = '# HELP nexus_actor_mailbox_depth Current number of messages in actor mailbox';
            $lines[] = '# TYPE nexus_actor_mailbox_depth gauge';
            $lines[] = '# HELP nexus_actor_alive Whether the actor is alive (1=yes, 0=no)';
            $lines[] = '# TYPE nexus_actor_alive gauge';

            foreach ($systemSnapshot->actors as $actor) {
                $this->appendActorMetrics($lines, $actor, $systemName);
            }

            // Runtime metrics
            $lines[] = '# HELP nexus_coroutine_num Current number of Swoole coroutines';
            $lines[] = '# TYPE nexus_coroutine_num gauge';
            $lines[] = "nexus_coroutine_num{system=\"{$systemName}\"} {$runtimeSnapshot->coroutineNum}";
            $lines[] = '# HELP nexus_coroutine_peak_num Peak number of Swoole coroutines';
            $lines[] = '# TYPE nexus_coroutine_peak_num gauge';
            $lines[] = "nexus_coroutine_peak_num{system=\"{$systemName}\"} {$runtimeSnapshot->coroutinePeakNum}";
            $lines[] = '# HELP nexus_active_timers Number of active Swoole timers';
            $lines[] = '# TYPE nexus_active_timers gauge';
            $lines[] = "nexus_active_timers{system=\"{$systemName}\"} {$runtimeSnapshot->activeTimers}";
            $lines[] = '# HELP nexus_memory_bytes Current memory usage in bytes';
            $lines[] = '# TYPE nexus_memory_bytes gauge';
            $lines[] = "nexus_memory_bytes{system=\"{$systemName}\"} {$runtimeSnapshot->memoryBytes}";
            $lines[] = '# HELP nexus_memory_peak_bytes Peak memory usage in bytes';
            $lines[] = '# TYPE nexus_memory_peak_bytes gauge';
            $lines[] = "nexus_memory_peak_bytes{system=\"{$systemName}\"} {$runtimeSnapshot->memoryPeakBytes}";

            $res->header('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');
            $res->end(implode("\n", $lines) . "\n");
        });

        Coroutine::create(static function () use ($server): void {
            $server->start();
        });
    }

    /**
     * @param list<string> $lines
     */
    private function appendActorMetrics(array &$lines, ActorSnapshot $actor, string $systemName): void
    {
        $path = $actor->path;
        $alive = $actor->alive ? 1 : 0;

        $lines[] = "nexus_actor_mailbox_depth{system=\"{$systemName}\",actor=\"{$path}\"} {$actor->mailboxDepth}";
        $lines[] = "nexus_actor_alive{system=\"{$systemName}\",actor=\"{$path}\"} {$alive}";

        foreach ($actor->children as $child) {
            $this->appendActorMetrics($lines, $child, $systemName);
        }
    }
}
```

**Step 4: Run integration test**

```bash
docker compose exec php-swoole vendor/bin/phpunit \
  tests/Integration/Swoole/TelemetryServerTest.php
```

Expected: PASS (2 tests).

**Step 5: Commit**

```bash
git add packages/nexus-runtime-swoole/src/Telemetry/TelemetryServer.php \
        tests/Integration/Swoole/TelemetryServerTest.php
git commit -m "feat(telemetry): TelemetryServer with /status and /metrics endpoints"
```

---

## Task 6: `WorkerTelemetryPublisher` and wire into `WorkerRunnable`

**Files:**
- Create: `packages/nexus-worker-pool-swoole/src/Telemetry/WorkerTelemetryPublisher.php`
- Modify: `packages/nexus-worker-pool-swoole/src/WorkerRunnable.php`
- Test: `packages/nexus-worker-pool-swoole/tests/Unit/Telemetry/WorkerTelemetryPublisherTest.php`

**Context:** `WorkerTelemetryPublisher` runs a background coroutine inside a worker thread. It periodically snapshots the local `ActorSystem` + `SwooleRuntime` and writes JSON to a shared `Swoole\Thread\Map` under key `"telemetry:worker-{id}"`. The key namespace `"telemetry:"` avoids collision with actor directory keys (which are actor paths like `/user/orders`).

**Step 1: Write the unit test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\WorkerPool\Swoole\Tests\Unit\Telemetry;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use Monadial\Nexus\WorkerPool\Swoole\Telemetry\WorkerTelemetryPublisher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Thread\Map;

use function Swoole\Coroutine\run;

#[CoversClass(WorkerTelemetryPublisher::class)]
final class WorkerTelemetryPublisherTest extends TestCase
{
    #[Test]
    public function publisher_writes_telemetry_to_map_on_start(): void
    {
        $map = new Map();
        $captured = [];

        run(static function () use ($map, &$captured): void {
            $runtime = new SwooleRuntime();
            $system = ActorSystem::create('worker-0', $runtime);

            $system->spawn(
                Props::fromBehavior(Behavior::receive(static fn($ctx, $msg) => Behavior::same())),
                'orders',
            );

            $publisher = new WorkerTelemetryPublisher(
                workerId: 0,
                system: $system,
                runtime: $runtime,
                map: $map,
                interval: Duration::millis(50),
            );
            $publisher->start();

            // Wait for at least one write
            Coroutine::sleep(0.1);

            $captured['raw'] = $map['telemetry:worker-0'] ?? null;

            $system->shutdown(Duration::seconds(1));
        });

        self::assertNotNull($captured['raw']);
        $data = json_decode($captured['raw'], true);
        self::assertSame('worker-0', $data['system']['name']);
        self::assertArrayHasKey('runtime', $data);
        self::assertArrayHasKey('coroutine_num', $data['runtime']);

        // Actor hierarchy present
        $actors = $data['system']['actors'];
        self::assertCount(1, $actors);
        self::assertSame('/user/orders', $actors[0]['path']);
    }
}
```

**Step 2: Run to confirm failure**

```bash
docker compose exec php-swoole vendor/bin/phpunit \
  packages/nexus-worker-pool-swoole/tests/Unit/Telemetry/WorkerTelemetryPublisherTest.php
```

Expected: FAIL — class not found.

**Step 3: Create `WorkerTelemetryPublisher`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\WorkerPool\Swoole\Telemetry;

use JsonException;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use Swoole\Thread\Map;

/**
 * @psalm-api
 *
 * Publishes per-worker telemetry to a shared Thread\Map at a regular interval.
 *
 * Runs a background coroutine inside the worker thread. Uses key
 * "telemetry:worker-{workerId}" to avoid collision with actor directory
 * entries (which use path-style keys like "/user/orders").
 *
 * @psalm-suppress UndefinedClass, MissingDependency
 */
final class WorkerTelemetryPublisher
{
    private const string KEY_PREFIX = 'telemetry:worker-';

    public function __construct(
        private readonly int $workerId,
        private readonly ActorSystem $system,
        private readonly SwooleRuntime $runtime,
        private readonly Map $map,
        private readonly Duration $interval,
    ) {}

    /**
     * Spawns the background publish loop. Non-blocking.
     */
    public function start(): void
    {
        $this->write();

        $this->runtime->scheduleRepeatedly(
            $this->interval,
            $this->interval,
            fn(): void => $this->write(),
        );
    }

    private function write(): void
    {
        $systemSnapshot = $this->system->snapshot();
        $runtimeSnapshot = $this->runtime->snapshot();

        try {
            $json = json_encode([
                'runtime' => $runtimeSnapshot->toArray(),
                'system' => $systemSnapshot->toArray(),
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return;
        }

        $this->map[self::KEY_PREFIX . $this->workerId] = $json;
    }
}
```

**Step 4: Wire `WorkerTelemetryPublisher` into `WorkerRunnable`**

In `packages/nexus-worker-pool-swoole/src/WorkerRunnable.php`, add import:
```php
use Monadial\Nexus\WorkerPool\Swoole\Telemetry\WorkerTelemetryPublisher;
```

In `run()`, after the `$configure($node)` / `$handler->onWorkerStart($node)` block and before `$system->run()`:

```php
$telemetry = new WorkerTelemetryPublisher(
    workerId: $workerId,
    system: $system,
    runtime: $runtime,
    map: $this->directory,
    interval: Duration::seconds(5),
);
$telemetry->start();

$system->run();
```

**Step 5: Run tests**

```bash
docker compose exec php-swoole vendor/bin/phpunit \
  packages/nexus-worker-pool-swoole/tests/Unit/Telemetry/WorkerTelemetryPublisherTest.php
```

Expected: PASS (1 test).

```bash
docker compose exec php-swoole vendor/bin/phpunit --testsuite=worker-pool
```

Expected: all passing.

**Step 6: Commit**

```bash
git add packages/nexus-worker-pool-swoole/src/Telemetry/WorkerTelemetryPublisher.php \
        packages/nexus-worker-pool-swoole/src/WorkerRunnable.php \
        packages/nexus-worker-pool-swoole/tests/Unit/Telemetry/WorkerTelemetryPublisherTest.php
git commit -m "feat(telemetry): WorkerTelemetryPublisher writes per-worker stats to Thread\Map"
```

---

## Task 7: `WorkerPoolSnapshot` and `WorkerPoolTelemetryServer`

**Files:**
- Create: `packages/nexus-worker-pool-swoole/src/Telemetry/WorkerPoolSnapshot.php`
- Create: `packages/nexus-worker-pool-swoole/src/Telemetry/WorkerPoolTelemetryServer.php`
- Test: `packages/nexus-worker-pool-swoole/tests/Unit/Telemetry/WorkerPoolTelemetryServerTest.php`

**Context:** `WorkerPoolTelemetryServer` runs in the main thread (accessed via `WorkerPoolHandle::directory()`). It reads all `"telemetry:worker-*"` keys from the shared `Thread\Map` and aggregates them. In test it can be instantiated with a mock `Map` containing pre-encoded JSON.

**Step 1: Write the unit test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\WorkerPool\Swoole\Tests\Unit\Telemetry;

use Monadial\Nexus\WorkerPool\Swoole\Telemetry\WorkerPoolTelemetryServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Thread\Map;

#[CoversClass(WorkerPoolTelemetryServer::class)]
final class WorkerPoolTelemetryServerTest extends TestCase
{
    #[Test]
    public function aggregate_merges_all_worker_entries(): void
    {
        $map = new Map();

        $worker0 = [
            'system' => [
                'actors' => [
                    ['alive' => true, 'children' => [], 'mailbox_bounded' => false,
                     'mailbox_capacity' => PHP_INT_MAX, 'mailbox_depth' => 0, 'path' => '/user/orders'],
                ],
                'dead_letters_count' => 0,
                'is_running' => true,
                'name' => 'nexus-0',
                'writer_id' => 'ULID0',
            ],
            'runtime' => [
                'active_timers' => 2,
                'coroutine_num' => 10,
                'coroutine_peak_num' => 15,
                'memory_bytes' => 1_000_000,
                'memory_peak_bytes' => 2_000_000,
            ],
        ];

        $worker1 = [
            'system' => [
                'actors' => [
                    ['alive' => true, 'children' => [], 'mailbox_bounded' => false,
                     'mailbox_capacity' => PHP_INT_MAX, 'mailbox_depth' => 3, 'path' => '/user/payments'],
                ],
                'dead_letters_count' => 1,
                'is_running' => true,
                'name' => 'nexus-1',
                'writer_id' => 'ULID1',
            ],
            'runtime' => [
                'active_timers' => 1,
                'coroutine_num' => 8,
                'coroutine_peak_num' => 12,
                'memory_bytes' => 800_000,
                'memory_peak_bytes' => 1_500_000,
            ],
        ];

        $map['telemetry:worker-0'] = json_encode($worker0);
        $map['telemetry:worker-1'] = json_encode($worker1);

        $server = new WorkerPoolTelemetryServer($map, host: '127.0.0.1', port: 19504);
        $snapshot = $server->aggregate();

        self::assertCount(2, $snapshot['workers']);
        self::assertSame(18, $snapshot['aggregates']['total_coroutines']);
        self::assertSame(3, $snapshot['aggregates']['total_timers']);
        self::assertSame(1_800_000, $snapshot['aggregates']['total_memory_bytes']);
        self::assertSame(1, $snapshot['aggregates']['total_dead_letters']);
    }
}
```

**Step 2: Run to confirm failure**

```bash
docker compose exec php-swoole vendor/bin/phpunit \
  packages/nexus-worker-pool-swoole/tests/Unit/Telemetry/WorkerPoolTelemetryServerTest.php
```

Expected: FAIL — class not found.

**Step 3: Create `WorkerPoolTelemetryServer`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\WorkerPool\Swoole\Telemetry;

use Swoole\Coroutine;
use Swoole\Coroutine\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Thread\Map;

/**
 * @psalm-api
 *
 * HTTP server running in the main thread that aggregates telemetry from all
 * worker threads via the shared Thread\Map.
 *
 * Must be started inside a coroutine context. Typically created from the
 * WorkerPoolBootstrap::withOnStart() callback using WorkerPoolHandle::directory().
 *
 * Usage:
 *     $bootstrap->withOnStart(function (WorkerPoolHandle $handle): void {
 *         $telemetry = new WorkerPoolTelemetryServer($handle->directory());
 *         $telemetry->start();
 *         // ... keep main thread alive
 *     });
 *
 * @psalm-suppress UndefinedClass, MissingDependency
 */
final class WorkerPoolTelemetryServer
{
    private const string KEY_PREFIX = 'telemetry:worker-';

    public function __construct(
        private readonly Map $map,
        private readonly string $host = '0.0.0.0',
        private readonly int $port = 9502,
    ) {}

    /**
     * Start the HTTP server in a new coroutine. Non-blocking.
     */
    public function start(): void
    {
        $server = new Server($this->host, $this->port, false, true);

        $server->handle('/status', function (Request $req, Response $res): void {
            $snapshot = $this->aggregate();
            $snapshot['mode'] = 'worker-pool';

            $body = json_encode($snapshot, JSON_THROW_ON_ERROR);

            $res->header('Content-Type', 'application/json');
            $res->end($body);
        });

        $server->handle('/metrics', function (Request $req, Response $res): void {
            $snapshot = $this->aggregate();
            $lines = $this->buildPrometheusLines($snapshot);

            $res->header('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');
            $res->end(implode("\n", $lines) . "\n");
        });

        Coroutine::create(static function () use ($server): void {
            $server->start();
        });
    }

    /**
     * Read all worker entries from Thread\Map and aggregate into a single array.
     *
     * @return array<string, mixed>
     */
    public function aggregate(): array
    {
        $workers = [];
        $totalCoroutines = 0;
        $totalTimers = 0;
        $totalMemoryBytes = 0;
        $totalDeadLetters = 0;

        foreach ($this->map as $key => $value) {
            if (!str_starts_with((string) $key, self::KEY_PREFIX)) {
                continue;
            }

            $workerId = (int) substr((string) $key, strlen(self::KEY_PREFIX));

            /** @var array<string, mixed>|null $data */
            $data = json_decode((string) $value, true);

            if ($data === null) {
                continue;
            }

            $workers[] = [
                'system' => $data['system'],
                'runtime' => $data['runtime'],
                'worker_id' => $workerId,
            ];

            /** @var array<string, int> $runtime */
            $runtime = $data['runtime'];
            $totalCoroutines += $runtime['coroutine_num'] ?? 0;
            $totalTimers += $runtime['active_timers'] ?? 0;
            $totalMemoryBytes += $runtime['memory_bytes'] ?? 0;

            /** @var array<string, mixed> $system */
            $system = $data['system'];
            $totalDeadLetters += (int) ($system['dead_letters_count'] ?? 0);
        }

        return [
            'aggregates' => [
                'total_coroutines' => $totalCoroutines,
                'total_dead_letters' => $totalDeadLetters,
                'total_memory_bytes' => $totalMemoryBytes,
                'total_timers' => $totalTimers,
            ],
            'workers' => $workers,
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return list<string>
     */
    private function buildPrometheusLines(array $snapshot): array
    {
        $lines = [];
        $lines[] = '# HELP nexus_actor_mailbox_depth Current number of messages in actor mailbox';
        $lines[] = '# TYPE nexus_actor_mailbox_depth gauge';
        $lines[] = '# HELP nexus_actor_alive Whether the actor is alive (1=yes, 0=no)';
        $lines[] = '# TYPE nexus_actor_alive gauge';

        /** @var array<int, array<string, mixed>> $workers */
        $workers = $snapshot['workers'] ?? [];

        foreach ($workers as $workerData) {
            $workerId = (string) ($workerData['worker_id'] ?? '?');

            /** @var array<string, mixed> $system */
            $system = $workerData['system'];
            $systemName = (string) ($system['name'] ?? '');

            /** @var array<int, array<string, mixed>> $actors */
            $actors = $system['actors'] ?? [];

            foreach ($actors as $actor) {
                $this->appendActorMetricsLines($lines, $actor, $systemName, $workerId);
            }

            /** @var array<string, int> $runtime */
            $runtime = $workerData['runtime'];

            $lines[] = "nexus_coroutine_num{system=\"{$systemName}\",worker=\"{$workerId}\"} " . ($runtime['coroutine_num'] ?? 0);
            $lines[] = "nexus_active_timers{system=\"{$systemName}\",worker=\"{$workerId}\"} " . ($runtime['active_timers'] ?? 0);
            $lines[] = "nexus_memory_bytes{system=\"{$systemName}\",worker=\"{$workerId}\"} " . ($runtime['memory_bytes'] ?? 0);
        }

        /** @var array<string, int> $aggregates */
        $aggregates = $snapshot['aggregates'] ?? [];

        $lines[] = '# HELP nexus_total_coroutines Total coroutines across all workers';
        $lines[] = '# TYPE nexus_total_coroutines gauge';
        $lines[] = 'nexus_total_coroutines ' . ($aggregates['total_coroutines'] ?? 0);
        $lines[] = '# HELP nexus_total_memory_bytes Total memory usage across all workers';
        $lines[] = '# TYPE nexus_total_memory_bytes gauge';
        $lines[] = 'nexus_total_memory_bytes ' . ($aggregates['total_memory_bytes'] ?? 0);

        return $lines;
    }

    /**
     * @param list<string> $lines
     * @param array<string, mixed> $actor
     */
    private function appendActorMetricsLines(
        array &$lines,
        array $actor,
        string $systemName,
        string $workerId,
    ): void {
        $path = (string) ($actor['path'] ?? '');
        $alive = ((bool) ($actor['alive'] ?? false)) ? 1 : 0;
        $depth = (int) ($actor['mailbox_depth'] ?? 0);

        $lines[] = "nexus_actor_mailbox_depth{system=\"{$systemName}\",actor=\"{$path}\",worker=\"{$workerId}\"} {$depth}";
        $lines[] = "nexus_actor_alive{system=\"{$systemName}\",actor=\"{$path}\",worker=\"{$workerId}\"} {$alive}";

        /** @var array<int, array<string, mixed>> $children */
        $children = $actor['children'] ?? [];

        foreach ($children as $child) {
            $this->appendActorMetricsLines($lines, $child, $systemName, $workerId);
        }
    }
}
```

**Step 4: Run tests**

```bash
docker compose exec php-swoole vendor/bin/phpunit \
  packages/nexus-worker-pool-swoole/tests/Unit/Telemetry/WorkerPoolTelemetryServerTest.php
```

Expected: PASS (1 test).

**Step 5: Run all worker-pool tests**

```bash
docker compose exec php-swoole vendor/bin/phpunit --testsuite=worker-pool
```

Expected: all passing.

**Step 6: Commit**

```bash
git add packages/nexus-worker-pool-swoole/src/Telemetry/ \
        packages/nexus-worker-pool-swoole/tests/Unit/Telemetry/WorkerPoolTelemetryServerTest.php
git commit -m "feat(telemetry): WorkerPoolTelemetryServer aggregates all workers via Thread\Map"
```

---

## Task 8: Full test pass and static analysis

**Step 1: Run full unit test suite**

```bash
docker compose exec php vendor/bin/phpunit --testsuite=unit
```

Expected: all passing.

**Step 2: Run all Swoole tests**

```bash
docker compose exec php-swoole vendor/bin/phpunit --testsuite=unit
docker compose exec php-swoole vendor/bin/phpunit --testsuite=integration-swoole
docker compose exec php-swoole vendor/bin/phpunit --testsuite=worker-pool
```

Expected: all passing.

**Step 3: Run static analysis**

```bash
make psalm
```

Fix any Psalm level 1 errors. Common patterns:
- Use `@psalm-suppress UnusedClass` on new public API classes with `@psalm-api`
- For `Swoole\Thread\Map` iteration, use `/** @psalm-suppress ... */` for any `UndefinedClass` errors
- Arrays with string keys must be sorted alphabetically — check all `toArray()` methods (already sorted in the code above)

**Step 4: Run code style**

```bash
make cs-fix
make phpcbf
```

Fix any style violations.

**Step 5: Run full suite one more time**

```bash
docker compose exec php vendor/bin/phpunit --testsuite=unit && \
docker compose exec php-swoole vendor/bin/phpunit --testsuite=unit && \
docker compose exec php-swoole vendor/bin/phpunit --testsuite=worker-pool
```

**Step 6: Commit any style/analysis fixes**

```bash
git add -A
git commit -m "fix(telemetry): resolve Psalm and code-style issues"
```

---

## Notes for the Implementer

### Key invariants to preserve

- `nexus-core` must not import from any other Nexus package. `ActorSnapshot`, `ActorSystemSnapshot` — no external dependencies.
- `nexus-runtime-swoole` may depend on `nexus-core` and `nexus-runtime` only.
- `nexus-worker-pool-swoole` may depend on `nexus-worker-pool`, `nexus-core`, `nexus-runtime-swoole`.
- All new value objects must be `final readonly class`.
- All arrays with string keys must be sorted alphabetically (PHPCS enforces this).
- Blank line required before `if`/`foreach`/`while` blocks inside methods.

### How to find all `new ActorCell(` call sites

```bash
grep -rn "new ActorCell(" packages/ tests/ --include="*.php"
```

Every call site needs the `MailboxConfig` added as 4th argument. In production code use `$props->mailbox`. In tests use `MailboxConfig::unbounded()` unless the test is specifically testing bounded mailboxes.

### `WorkerPoolTelemetryServer` usage pattern

The `WorkerPoolTelemetryServer` is deliberately NOT wired into `WorkerRunnable` or `WorkerPoolBootstrap` automatically. It is user-space opt-in from the `onStart` callback:

```php
WorkerPoolBootstrap::create($config)
    ->withHandler(MyApp::class)
    ->withOnStart(function (WorkerPoolHandle $handle): void {
        $telemetry = new WorkerPoolTelemetryServer($handle->directory(), port: 9502);
        $telemetry->start();
        // Keep main thread running while workers are active
        while (true) {
            Coroutine::sleep(1);
        }
    })
    ->run();
```

### `Thread\Map` iteration

Iterating a `Swoole\Thread\Map` with `foreach` works the same as a regular array. Psalm may complain about `UndefinedClass` for Swoole thread classes — suppress with `@psalm-suppress UndefinedClass, MissingDependency` on the class docblock (already present in `WorkerRunnable`).
