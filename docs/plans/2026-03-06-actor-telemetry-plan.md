# Actor System Telemetry Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add a new `nexus-actors/observability` package with pull-based actor hierarchy snapshots, Swoole runtime stats, Prometheus metrics (via `promphp/prometheus_client_php`), and optional HTTP endpoints (`/status` JSON + `/metrics` Prometheus text). Also add comprehensive structured logging throughout the actor system.

**Architecture:** `ActorSystem` and `SwooleRuntime` gain `snapshot()` methods (pure reads) in their own packages. `nexus-observability` is a pure leaf package — it depends on the other packages, none of them depend on it. `WorkerTelemetryPublisher` writes per-worker snapshots to a shared `Thread\Map` every N seconds. `WorkerPoolTelemetryServer` aggregates from the map. Both HTTP server and Prometheus registry are opt-in. Logging is added with rich context (`actor_path`, `message_type`, `system_name`, `worker_id`, etc.) at appropriate levels.

**Tech Stack:** PHP 8.5, Swoole 6.0, `promphp/prometheus_client_php ^2.10`, PHPUnit 13

**Working directory for all commands:** `.worktrees/feat/symfony-integration`

**Design doc:** `docs/plans/2026-03-06-actor-telemetry-design.md`

---

## Task 1: nexus-observability package skeleton

**Files:**
- Create: `packages/nexus-observability/composer.json`
- Create: `packages/nexus-observability/src/.gitkeep`
- Modify: `composer.json` (root — add autoload + require)
- Modify: `phpunit.xml` (add to `unit-swoole` suite)
- Modify: `deptrac.yaml` (add Observability layer)

**Step 1: Create `packages/nexus-observability/composer.json`**

```json
{
    "name": "nexus-actors/observability",
    "description": "Nexus observability — actor hierarchy snapshots, Prometheus metrics, and HTTP telemetry endpoints.",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.5",
        "ext-swoole": "*",
        "nexus-actors/core": "dev-main",
        "nexus-actors/runtime": "dev-main",
        "nexus-actors/runtime-swoole": "dev-main",
        "nexus-actors/worker-pool": "dev-main",
        "nexus-actors/worker-pool-swoole": "dev-main",
        "promphp/prometheus_client_php": "^2.10"
    },
    "require-dev": {
        "phpunit/phpunit": "^13.0"
    },
    "autoload": {
        "psr-4": {
            "Monadial\\Nexus\\Observability\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Monadial\\Nexus\\Observability\\Tests\\": "tests/"
        }
    }
}
```

**Step 2: Add to root `composer.json`**

In `"autoload" > "psr-4"` (alphabetical order), add:
```json
"Monadial\\Nexus\\Observability\\": "packages/nexus-observability/src/"
```

In `"require"`, add:
```json
"promphp/prometheus_client_php": "^2.10"
```

**Step 3: Add to `phpunit.xml` — `unit-swoole` suite**

```xml
<directory>packages/nexus-observability/tests/Unit</directory>
```

**Step 4: Add to `deptrac.yaml`**

In `layers:`, add:
```yaml
- name: Observability
  collectors:
    - type: directory
      value: packages/nexus-observability/src/.*
```

In `ruleset:`, add:
```yaml
Observability:
  - Core
  - Runtime
  - RuntimeSwoole
  - WorkerPool
  - WorkerPoolSwoole
```

**Step 5: Install the new library**

```bash
docker compose exec php composer require promphp/prometheus_client_php:^2.10
```

**Step 6: Create directory structure**

```bash
mkdir -p packages/nexus-observability/src/{Data,Http,Prometheus,Publisher}
mkdir -p packages/nexus-observability/tests/Unit/{Data,Http,Prometheus,Publisher}
touch packages/nexus-observability/src/.gitkeep
```

**Step 7: Verify autoload**

```bash
docker compose exec php composer dump-autoload
```

Expected: no errors.

**Step 8: Commit**

```bash
git add packages/nexus-observability/ composer.json phpunit.xml deptrac.yaml
git commit -m "feat(observability): add nexus-observability package skeleton"
```

---

## Task 2: `ActorSnapshot` and `ActorSystemSnapshot` (nexus-core)

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
    public function actor_snapshot_exposes_fields(): void
    {
        $child = new ActorSnapshot('/user/orders/p', true, 0, PHP_INT_MAX, false, []);
        $snap  = new ActorSnapshot('/user/orders', true, 3, 1000, true, [$child]);

        self::assertSame('/user/orders', $snap->path);
        self::assertTrue($snap->alive);
        self::assertSame(3, $snap->mailboxDepth);
        self::assertSame(1000, $snap->mailboxCapacity);
        self::assertTrue($snap->mailboxBounded);
        self::assertCount(1, $snap->children);
    }

    #[Test]
    public function actor_snapshot_round_trips_through_array(): void
    {
        $child = new ActorSnapshot('/user/orders/p', true, 0, PHP_INT_MAX, false, []);
        $snap  = new ActorSnapshot('/user/orders', true, 3, 1000, true, [$child]);

        $restored = ActorSnapshot::fromArray($snap->toArray());

        self::assertSame($snap->path, $restored->path);
        self::assertSame($snap->mailboxDepth, $restored->mailboxDepth);
        self::assertCount(1, $restored->children);
        self::assertSame('/user/orders/p', $restored->children[0]->path);
    }

    #[Test]
    public function actor_system_snapshot_round_trips_through_array(): void
    {
        $actor = new ActorSnapshot('/user/orders', true, 0, PHP_INT_MAX, false, []);
        $snap  = new ActorSystemSnapshot('my-system', '01HXYZ', true, [$actor], 2);

        $restored = ActorSystemSnapshot::fromArray($snap->toArray());

        self::assertSame('my-system', $restored->systemName);
        self::assertSame('01HXYZ', $restored->writerId);
        self::assertTrue($restored->isRunning);
        self::assertSame(2, $restored->deadLettersCount);
        self::assertCount(1, $restored->actors);
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
 * Children are populated recursively, reflecting the real actor hierarchy.
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
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            path: (string) $data['path'],
            alive: (bool) $data['alive'],
            mailboxDepth: (int) $data['mailbox_depth'],
            mailboxCapacity: (int) $data['mailbox_capacity'],
            mailboxBounded: (bool) $data['mailbox_bounded'],
            children: array_map(
                static fn(array $c): self => self::fromArray($c),
                /** @var array<int, array<string, mixed>> */
                $data['children'] ?? [],
            ),
        );
    }

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
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            systemName: (string) $data['name'],
            writerId: (string) $data['writer_id'],
            isRunning: (bool) $data['is_running'],
            actors: array_map(
                static fn(array $a): ActorSnapshot => ActorSnapshot::fromArray($a),
                /** @var array<int, array<string, mixed>> */
                $data['actors'] ?? [],
            ),
            deadLettersCount: (int) $data['dead_letters_count'],
        );
    }

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

**Step 5: Run tests**

```bash
docker compose exec php vendor/bin/phpunit \
  packages/nexus-core/tests/Unit/Actor/Telemetry/ActorSnapshotTest.php
```

Expected: PASS (3 tests).

**Step 6: Commit**

```bash
git add packages/nexus-core/src/Actor/Telemetry/ \
        packages/nexus-core/tests/Unit/Actor/Telemetry/
git commit -m "feat(telemetry): ActorSnapshot and ActorSystemSnapshot value objects with round-trip serialization"
```

---

## Task 3: `ActorCell` — `MailboxConfig` + child cell tracking + `snapshot()`

**Files:**
- Modify: `packages/nexus-core/src/Actor/ActorCell.php`
- Test: `packages/nexus-core/tests/Unit/Actor/ActorCellSnapshotTest.php`

**Context:** `ActorCell` currently tracks children as `ActorRef[]` only — not enough for snapshot. We add `MailboxConfig` as a constructor parameter (4th arg, after `$mailbox`) and a parallel `$childCells` map. The child cells map lets `snapshot()` recurse through the hierarchy.

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
use Monadial\Nexus\Core\Supervision\SupervisionStrategy;
use Monadial\Nexus\Core\Tests\Support\TestClock;
use Monadial\Nexus\Core\Tests\Support\TestMailbox;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Runtime\Mailbox\MailboxConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(ActorCell::class)]
final class ActorCellSnapshotTest extends TestCase
{
    #[Test]
    public function snapshot_reflects_mailbox_config(): void
    {
        $cell = $this->makeCell(MailboxConfig::bounded(500));
        $cell->start();

        $snap = $cell->snapshot();

        self::assertInstanceOf(ActorSnapshot::class, $snap);
        self::assertSame('/user/orders', $snap->path);
        self::assertTrue($snap->alive);
        self::assertSame(0, $snap->mailboxDepth);
        self::assertSame(500, $snap->mailboxCapacity);
        self::assertTrue($snap->mailboxBounded);
        self::assertSame([], $snap->children);
    }

    #[Test]
    public function snapshot_includes_children_recursively(): void
    {
        $cell = $this->makeCell(MailboxConfig::unbounded());
        $cell->start();

        $cell->spawn(
            Props::fromBehavior(Behavior::receive(static fn($ctx, $msg) => Behavior::same())),
            'processor',
        );

        $snap = $cell->snapshot();

        self::assertCount(1, $snap->children);
        self::assertSame('/user/orders/processor', $snap->children[0]->path);
    }

    private function makeCell(MailboxConfig $config): ActorCell
    {
        return new ActorCell(
            Behavior::receive(static fn($ctx, $msg) => Behavior::same()),
            ActorPath::fromString('/user/orders'),
            new TestMailbox(),
            $config,
            new TestRuntime(),
            null,
            SupervisionStrategy::oneForOne(),
            new TestClock(),
            new NullLogger(),
            new DeadLetterRef(),
        );
    }
}
```

**Step 2: Run to confirm failure**

```bash
docker compose exec php vendor/bin/phpunit \
  packages/nexus-core/tests/Unit/Actor/ActorCellSnapshotTest.php
```

Expected: FAIL — constructor argument count mismatch; `snapshot()` not found.

**Step 3: Modify `ActorCell`**

Add imports (keep alphabetical within each group):
```php
use Monadial\Nexus\Core\Actor\Telemetry\ActorSnapshot;
use Monadial\Nexus\Runtime\Mailbox\MailboxConfig;
```

Add property after `$stashBuffer`:
```php
/** @var array<string, self<object>> */
private array $childCells = [];
```

Insert `MailboxConfig` as the 4th constructor parameter (after `Mailbox $mailbox`):
```php
public function __construct(
    Behavior $behavior,
    private readonly ActorPath $actorPath,
    private readonly Mailbox $mailbox,
    private readonly MailboxConfig $mailboxConfig,   // ← new
    private readonly Runtime $runtime,
    private readonly ?ActorRef $parentRef,
    private readonly SupervisionStrategy $supervision,
    private readonly ClockInterface $clock,
    private readonly LoggerInterface $logger,
    private readonly DeadLetterRef $deadLetters,
)
```

In `spawn()`, add `$props->mailbox` as 4th arg to `new self(...)` and store the child cell:
```php
$childCell = new self(
    $props->behavior,
    $childPath,
    $childMailbox,
    $props->mailbox,           // ← new
    $this->runtime,
    $parentRef,
    $typedSupervision,
    $this->clock,
    $this->logger,
    $this->deadLetters,
);
$childCell->start();

$this->spawnMessageLoop($childCell, $childMailbox);

$childRef = $childCell->self();
$this->childrenMap[$name] = $childRef;

/** @var self<object> $childCell */
$this->childCells[$name] = $childCell;   // ← new

return $childRef;
```

Add `snapshot()` after the `log()` method:
```php
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

Search:
```bash
grep -rn "new ActorCell(" packages/ tests/ --include="*.php"
```

For every match that doesn't already have `MailboxConfig`, insert `MailboxConfig::unbounded(),` as the 4th argument (or the appropriate config). `ActorSystem::createActorCell()` must pass `$props->mailbox`.

**Step 5: Run tests**

```bash
docker compose exec php vendor/bin/phpunit \
  packages/nexus-core/tests/Unit/Actor/ActorCellSnapshotTest.php
docker compose exec php vendor/bin/phpunit --testsuite=unit
```

Expected: all passing.

**Step 6: Commit**

```bash
git add packages/nexus-core/src/Actor/ActorCell.php \
        packages/nexus-core/tests/Unit/Actor/ActorCellSnapshotTest.php
git commit -m "feat(telemetry): ActorCell tracks child cells and exposes snapshot() with recursive hierarchy"
```

---

## Task 4: `ActorSystem::snapshot()`

**Files:**
- Modify: `packages/nexus-core/src/Actor/ActorSystem.php`
- Modify: `packages/nexus-core/tests/Unit/Actor/ActorSystemTest.php` (add test)

**Step 1: Write the failing test**

Add to `ActorSystemTest`:
```php
#[Test]
public function snapshot_returns_full_actor_hierarchy(): void
{
    $system = ActorSystem::create('snap-test', $this->runtime);
    $props  = Props::fromBehavior(Behavior::receive(static fn($ctx, $msg) => Behavior::same()));

    $system->spawn($props, 'orders');
    $system->spawn($props, 'payments');

    $snap = $system->snapshot();

    self::assertSame('snap-test', $snap->systemName);
    self::assertCount(2, $snap->actors);
    self::assertSame(0, $snap->deadLettersCount);

    $paths = array_map(static fn($a) => $a->path, $snap->actors);
    self::assertContains('/user/orders', $paths);
    self::assertContains('/user/payments', $paths);
}
```

**Step 2: Run to confirm failure**

```bash
docker compose exec php vendor/bin/phpunit \
  --filter=snapshot_returns_full_actor_hierarchy \
  packages/nexus-core/tests/Unit/Actor/ActorSystemTest.php
```

Expected: FAIL — `snapshot()` does not exist.

**Step 3: Modify `ActorSystem`**

Add import:
```php
use Monadial\Nexus\Core\Actor\Telemetry\ActorSystemSnapshot;
```

Add property alongside `$children`:
```php
/** @var array<string, ActorCell<object>> */
private array $childCells = [];
```

Change `createActorCell()` to return `ActorCell<T>` and pass `$props->mailbox`:
```php
/**
 * @template T of object
 * @param Props<T> $props
 * @return ActorCell<T>
 */
private function createActorCell(Props $props, string $name): ActorCell
{
    $childPath    = $this->userGuardianPath->child($name);
    /** @var Mailbox<Envelope> $childMailbox */
    $childMailbox = $this->runtime->createMailbox($props->mailbox);
    $supervision  = $props->supervision ?? SupervisionStrategy::oneForOne();

    /** @var ActorCell<T> $cell */
    $cell = new ActorCell(
        $props->behavior,
        $childPath,
        $childMailbox,
        $props->mailbox,
        $this->runtime,
        null,
        $supervision,
        $this->clock,
        $this->logger,
        $this->deadLetters,
    );
    $cell->start();

    $this->spawnMessageLoop($cell, $childMailbox);

    return $cell;
}
```

Update `spawn()` and `spawnAnonymous()` to store cells:
```php
public function spawn(Props $props, string $name): ActorRef
{
    if (isset($this->children[$name])) {
        throw new ActorNameExistsException($this->userGuardianPath, $name);
    }

    $cell                      = $this->createActorCell($props, $name);
    $this->children[$name]     = $cell->self();
    $this->childCells[$name]   = $cell;

    return $cell->self();
}

public function spawnAnonymous(Props $props): ActorRef
{
    $name                      = 'auto-' . $this->anonymousCounter++;
    $cell                      = $this->createActorCell($props, $name);
    $this->children[$name]     = $cell->self();
    $this->childCells[$name]   = $cell;

    return $cell->self();
}
```

Add `snapshot()`:
```php
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
docker compose exec php vendor/bin/phpunit packages/nexus-core/tests/Unit/Actor/ActorSystemTest.php
docker compose exec php vendor/bin/phpunit --testsuite=unit
```

Expected: all passing.

**Step 5: Commit**

```bash
git add packages/nexus-core/src/Actor/ActorSystem.php \
        packages/nexus-core/tests/Unit/Actor/ActorSystemTest.php
git commit -m "feat(telemetry): ActorSystem::snapshot() returns full recursive actor hierarchy"
```

---

## Task 5: `SwooleRuntimeSnapshot` and `SwooleRuntime::snapshot()`

**Files:**
- Create: `packages/nexus-runtime-swoole/src/Telemetry/SwooleRuntimeSnapshot.php`
- Modify: `packages/nexus-runtime-swoole/src/SwooleRuntime.php`
- Test: `packages/nexus-runtime-swoole/tests/Unit/Telemetry/SwooleRuntimeSnapshotTest.php`

**Step 1: Write failing test**

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
    public function round_trips_through_array(): void
    {
        $snap     = new SwooleRuntimeSnapshot(12, 20, 4, 8_388_608, 12_582_912);
        $restored = SwooleRuntimeSnapshot::fromArray($snap->toArray());

        self::assertSame(12, $restored->coroutineNum);
        self::assertSame(20, $restored->coroutinePeakNum);
        self::assertSame(4, $restored->activeTimers);
        self::assertSame(8_388_608, $restored->memoryBytes);
        self::assertSame(12_582_912, $restored->memoryPeakBytes);
    }
}
```

**Step 2: Run to confirm failure**

```bash
docker compose exec php-swoole vendor/bin/phpunit \
  packages/nexus-runtime-swoole/tests/Unit/Telemetry/SwooleRuntimeSnapshotTest.php
```

Expected: FAIL.

**Step 3: Create `SwooleRuntimeSnapshot`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Swoole\Telemetry;

/**
 * @psalm-api
 *
 * Immutable snapshot of SwooleRuntime's observable state at a point in time.
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
     * @param array<string, int> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            coroutineNum: $data['coroutine_num'],
            coroutinePeakNum: $data['coroutine_peak_num'],
            activeTimers: $data['active_timers'],
            memoryBytes: $data['memory_bytes'],
            memoryPeakBytes: $data['memory_peak_bytes'],
        );
    }

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
```

Add method after `isRunning()`:
```php
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
docker compose exec php-swoole vendor/bin/phpunit --testsuite=unit-swoole
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

## Task 6: `WorkerTelemetryEntry` and `WorkerPoolAggregation` (nexus-observability)

**Files:**
- Create: `packages/nexus-observability/src/Data/WorkerTelemetryEntry.php`
- Create: `packages/nexus-observability/src/Data/WorkerPoolAggregation.php`
- Test: `packages/nexus-observability/tests/Unit/Data/WorkerTelemetryEntryTest.php`
- Test: `packages/nexus-observability/tests/Unit/Data/WorkerPoolAggregationTest.php`

**Step 1: Write failing tests**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Tests\Unit\Data;

use Monadial\Nexus\Core\Actor\Telemetry\ActorSnapshot;
use Monadial\Nexus\Core\Actor\Telemetry\ActorSystemSnapshot;
use Monadial\Nexus\Observability\Data\WorkerPoolAggregation;
use Monadial\Nexus\Observability\Data\WorkerTelemetryEntry;
use Monadial\Nexus\Runtime\Swoole\Telemetry\SwooleRuntimeSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkerTelemetryEntry::class)]
#[CoversClass(WorkerPoolAggregation::class)]
final class WorkerTelemetryEntryTest extends TestCase
{
    #[Test]
    public function entry_round_trips_through_json(): void
    {
        $actor   = new ActorSnapshot('/user/orders', true, 3, 1000, true, []);
        $system  = new ActorSystemSnapshot('nexus-0', 'ULID0', true, [$actor], 0);
        $runtime = new SwooleRuntimeSnapshot(10, 15, 2, 1_000_000, 2_000_000);
        $entry   = new WorkerTelemetryEntry(0, $system, $runtime);

        $restored = WorkerTelemetryEntry::fromJson($entry->toJson());

        self::assertSame(0, $restored->workerId);
        self::assertSame('nexus-0', $restored->system->systemName);
        self::assertSame(10, $restored->runtime->coroutineNum);
        self::assertCount(1, $restored->system->actors);
        self::assertSame('/user/orders', $restored->system->actors[0]->path);
    }

    #[Test]
    public function aggregation_sums_totals_across_entries(): void
    {
        $makeEntry = static fn(int $workerId, int $coroutines, int $timers, int $memory, int $deadLetters) =>
            new WorkerTelemetryEntry(
                $workerId,
                new ActorSystemSnapshot("nexus-{$workerId}", 'ULID', true, [], $deadLetters),
                new SwooleRuntimeSnapshot($coroutines, $coroutines, $timers, $memory, $memory),
            );

        $agg = WorkerPoolAggregation::fromEntries(
            $makeEntry(0, 10, 2, 1_000_000, 0),
            $makeEntry(1, 8, 3, 800_000, 1),
        );

        self::assertCount(2, $agg->entries);
        self::assertSame(18, $agg->totalCoroutines);
        self::assertSame(5, $agg->totalTimers);
        self::assertSame(1_800_000, $agg->totalMemoryBytes);
        self::assertSame(1, $agg->totalDeadLetters);
    }
}
```

**Step 2: Run to confirm failure**

```bash
docker compose exec php-swoole vendor/bin/phpunit \
  packages/nexus-observability/tests/Unit/Data/WorkerTelemetryEntryTest.php
```

Expected: FAIL.

**Step 3: Create `WorkerTelemetryEntry`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Data;

use JsonException;
use Monadial\Nexus\Core\Actor\Telemetry\ActorSystemSnapshot;
use Monadial\Nexus\Runtime\Swoole\Telemetry\SwooleRuntimeSnapshot;

/**
 * @psalm-api
 *
 * Typed snapshot of one worker's telemetry. Used to carry data from worker
 * threads to the aggregating HTTP server via Thread\Map JSON serialization.
 */
final readonly class WorkerTelemetryEntry
{
    public function __construct(
        public int $workerId,
        public ActorSystemSnapshot $system,
        public SwooleRuntimeSnapshot $runtime,
    ) {}

    /**
     * @throws JsonException
     */
    public function toJson(): string
    {
        return json_encode([
            'runtime' => $this->runtime->toArray(),
            'system' => $this->system->toArray(),
            'worker_id' => $this->workerId,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @throws JsonException
     */
    public static function fromJson(string $json): self
    {
        /** @var array{worker_id: int, system: array<string, mixed>, runtime: array<string, int>} $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return new self(
            workerId: $data['worker_id'],
            system: ActorSystemSnapshot::fromArray($data['system']),
            runtime: SwooleRuntimeSnapshot::fromArray($data['runtime']),
        );
    }
}
```

**Step 4: Create `WorkerPoolAggregation`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Data;

/**
 * @psalm-api
 *
 * Aggregated telemetry from all worker threads in a pool.
 */
final readonly class WorkerPoolAggregation
{
    /**
     * @param WorkerTelemetryEntry[] $entries
     */
    public function __construct(
        public array $entries,
        public int $totalCoroutines,
        public int $totalDeadLetters,
        public int $totalMemoryBytes,
        public int $totalTimers,
    ) {}

    public static function fromEntries(WorkerTelemetryEntry ...$entries): self
    {
        $totalCoroutines  = 0;
        $totalDeadLetters = 0;
        $totalMemoryBytes = 0;
        $totalTimers      = 0;

        foreach ($entries as $entry) {
            $totalCoroutines  += $entry->runtime->coroutineNum;
            $totalDeadLetters += $entry->system->deadLettersCount;
            $totalMemoryBytes += $entry->runtime->memoryBytes;
            $totalTimers      += $entry->runtime->activeTimers;
        }

        return new self(
            entries: array_values($entries),
            totalCoroutines: $totalCoroutines,
            totalDeadLetters: $totalDeadLetters,
            totalMemoryBytes: $totalMemoryBytes,
            totalTimers: $totalTimers,
        );
    }
}
```

**Step 5: Run tests**

```bash
docker compose exec php-swoole vendor/bin/phpunit \
  packages/nexus-observability/tests/Unit/Data/WorkerTelemetryEntryTest.php
```

Expected: PASS (2 tests).

**Step 6: Commit**

```bash
git add packages/nexus-observability/src/Data/ \
        packages/nexus-observability/tests/Unit/Data/
git commit -m "feat(observability): WorkerTelemetryEntry and WorkerPoolAggregation typed value objects"
```

---

## Task 7: `PrometheusCollector` (nexus-observability)

**Files:**
- Create: `packages/nexus-observability/src/Prometheus/PrometheusCollector.php`
- Test: `packages/nexus-observability/tests/Unit/Prometheus/PrometheusCollectorTest.php`

**Context:** `PrometheusCollector` wraps a `promphp/prometheus_client_php` `CollectorRegistry` with an `InMemory` adapter. Calling `collect()` updates all gauges; `render()` outputs Prometheus text format. Multiple `collect()` calls with different worker labels populate the same registry — supporting both standalone (one collect, no worker label) and pool (N collects, worker labels) modes.

**Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Tests\Unit\Prometheus;

use Monadial\Nexus\Core\Actor\Telemetry\ActorSnapshot;
use Monadial\Nexus\Core\Actor\Telemetry\ActorSystemSnapshot;
use Monadial\Nexus\Observability\Prometheus\PrometheusCollector;
use Monadial\Nexus\Runtime\Swoole\Telemetry\SwooleRuntimeSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PrometheusCollector::class)]
final class PrometheusCollectorTest extends TestCase
{
    #[Test]
    public function render_includes_actor_and_runtime_metrics(): void
    {
        $actor   = new ActorSnapshot('/user/orders', true, 3, 1000, true, []);
        $system  = new ActorSystemSnapshot('nexus-0', 'ULID', true, [$actor], 0);
        $runtime = new SwooleRuntimeSnapshot(12, 20, 4, 8_388_608, 12_582_912);

        $collector = new PrometheusCollector();
        $collector->collect($system, $runtime);
        $output = $collector->render();

        self::assertStringContainsString('nexus_actor_mailbox_depth', $output);
        self::assertStringContainsString('/user/orders', $output);
        self::assertStringContainsString('nexus_coroutine_num', $output);
        self::assertStringContainsString('12', $output);
        self::assertStringContainsString('nexus_memory_bytes', $output);
    }

    #[Test]
    public function render_includes_worker_label_when_provided(): void
    {
        $system  = new ActorSystemSnapshot('nexus-0', 'ULID', true, [], 0);
        $runtime = new SwooleRuntimeSnapshot(10, 15, 2, 1_000_000, 2_000_000);

        $collector = new PrometheusCollector();
        $collector->collect($system, $runtime, '0');
        $output = $collector->render();

        self::assertStringContainsString('worker="0"', $output);
    }
}
```

**Step 2: Run to confirm failure**

```bash
docker compose exec php-swoole vendor/bin/phpunit \
  packages/nexus-observability/tests/Unit/Prometheus/PrometheusCollectorTest.php
```

Expected: FAIL.

**Step 3: Create `PrometheusCollector`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Prometheus;

use Monadial\Nexus\Core\Actor\Telemetry\ActorSnapshot;
use Monadial\Nexus\Core\Actor\Telemetry\ActorSystemSnapshot;
use Monadial\Nexus\Runtime\Swoole\Telemetry\SwooleRuntimeSnapshot;
use Prometheus\CollectorRegistry;
use Prometheus\Gauge;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\InMemory;

/**
 * @psalm-api
 *
 * Populates a Prometheus registry from actor system and runtime snapshots.
 *
 * Each PrometheusCollector instance owns its own InMemory registry — create a
 * fresh instance per render cycle. Call collect() once per worker (with a
 * worker label) then call render() to produce Prometheus text format output.
 *
 * Standalone usage (no worker label):
 *     $c = new PrometheusCollector();
 *     $c->collect($system->snapshot(), $runtime->snapshot());
 *     echo $c->render();
 *
 * Worker pool usage (one collect per worker):
 *     $c = new PrometheusCollector();
 *     foreach ($aggregation->entries as $entry) {
 *         $c->collect($entry->system, $entry->runtime, (string) $entry->workerId);
 *     }
 *     echo $c->render();
 */
final class PrometheusCollector
{
    private CollectorRegistry $registry;

    private Gauge $mailboxDepth;

    private Gauge $actorAlive;

    private Gauge $coroutineNum;

    private Gauge $coroutinePeakNum;

    private Gauge $activeTimers;

    private Gauge $memoryBytes;

    private Gauge $memoryPeakBytes;

    private Gauge $deadLettersCount;

    public function __construct()
    {
        $this->registry = new CollectorRegistry(new InMemory());

        $this->mailboxDepth = $this->registry->getOrRegisterGauge(
            'nexus',
            'actor_mailbox_depth',
            'Current number of messages in actor mailbox',
            ['system', 'actor', 'worker'],
        );
        $this->actorAlive = $this->registry->getOrRegisterGauge(
            'nexus',
            'actor_alive',
            'Whether the actor is alive (1=yes, 0=no)',
            ['system', 'actor', 'worker'],
        );
        $this->coroutineNum = $this->registry->getOrRegisterGauge(
            'nexus',
            'coroutine_num',
            'Current number of Swoole coroutines',
            ['system', 'worker'],
        );
        $this->coroutinePeakNum = $this->registry->getOrRegisterGauge(
            'nexus',
            'coroutine_peak_num',
            'Peak number of Swoole coroutines',
            ['system', 'worker'],
        );
        $this->activeTimers = $this->registry->getOrRegisterGauge(
            'nexus',
            'active_timers',
            'Number of active Swoole timers',
            ['system', 'worker'],
        );
        $this->memoryBytes = $this->registry->getOrRegisterGauge(
            'nexus',
            'memory_bytes',
            'Current memory usage in bytes',
            ['system', 'worker'],
        );
        $this->memoryPeakBytes = $this->registry->getOrRegisterGauge(
            'nexus',
            'memory_peak_bytes',
            'Peak memory usage in bytes',
            ['system', 'worker'],
        );
        $this->deadLettersCount = $this->registry->getOrRegisterGauge(
            'nexus',
            'dead_letters_total',
            'Total number of dead letters received',
            ['system', 'worker'],
        );
    }

    /**
     * Collect metrics from one actor system + runtime pair.
     *
     * @param string $worker Empty string for standalone, worker ID for pool.
     */
    public function collect(
        ActorSystemSnapshot $system,
        SwooleRuntimeSnapshot $runtime,
        string $worker = '',
    ): void {
        $name = $system->systemName;

        foreach ($system->actors as $actor) {
            $this->collectActor($actor, $name, $worker);
        }

        $this->coroutineNum->set($runtime->coroutineNum, [$name, $worker]);
        $this->coroutinePeakNum->set($runtime->coroutinePeakNum, [$name, $worker]);
        $this->activeTimers->set($runtime->activeTimers, [$name, $worker]);
        $this->memoryBytes->set($runtime->memoryBytes, [$name, $worker]);
        $this->memoryPeakBytes->set($runtime->memoryPeakBytes, [$name, $worker]);
        $this->deadLettersCount->set($system->deadLettersCount, [$name, $worker]);
    }

    public function render(): string
    {
        $renderer = new RenderTextFormat();

        return $renderer->render($this->registry->getMetricFamilySamples());
    }

    private function collectActor(ActorSnapshot $actor, string $systemName, string $worker): void
    {
        $path = $actor->path;

        $this->mailboxDepth->set($actor->mailboxDepth, [$systemName, $path, $worker]);
        $this->actorAlive->set($actor->alive ? 1 : 0, [$systemName, $path, $worker]);

        foreach ($actor->children as $child) {
            $this->collectActor($child, $systemName, $worker);
        }
    }
}
```

**Step 4: Run tests**

```bash
docker compose exec php-swoole vendor/bin/phpunit \
  packages/nexus-observability/tests/Unit/Prometheus/PrometheusCollectorTest.php
```

Expected: PASS (2 tests).

**Step 5: Commit**

```bash
git add packages/nexus-observability/src/Prometheus/ \
        packages/nexus-observability/tests/Unit/Prometheus/
git commit -m "feat(observability): PrometheusCollector using promphp/prometheus_client_php"
```

---

## Task 8: `TelemetryServer` — standalone opt-in HTTP (nexus-observability)

**Files:**
- Create: `packages/nexus-observability/src/Http/TelemetryServer.php`
- Test: `tests/Integration/Swoole/TelemetryServerTest.php`

**Context:** Completely opt-in. Users create it only when they want the HTTP endpoint. The actor system and runtime run without any knowledge of it. In standalone mode (single Swoole process, no WorkerPool) this is the only server needed.

**Step 1: Write integration test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Swoole;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Observability\Http\TelemetryServer;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
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
    public function status_endpoint_returns_actor_hierarchy(): void
    {
        $captured = [];

        run(static function () use (&$captured): void {
            $runtime = new SwooleRuntime();
            $system  = ActorSystem::create('telemetry-test', $runtime);

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
            $captured['body']   = json_decode($client->body, true);
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
            $system  = ActorSystem::create('prom-test', $runtime);

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
            $captured['body']   = $client->body;
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

Expected: FAIL.

**Step 3: Create `TelemetryServer`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Http;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Observability\Prometheus\PrometheusCollector;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;

/**
 * @psalm-api
 *
 * Opt-in HTTP server exposing telemetry for a standalone (non-WorkerPool) Nexus system.
 *
 * Must be started inside a coroutine context. Never blocks.
 * The ActorSystem and SwooleRuntime run with zero knowledge of this server.
 *
 * Usage:
 *     $server = new TelemetryServer($system, $runtime, port: 9502);
 *     $server->start(); // non-blocking
 *
 * Endpoints:
 *     GET /status  — JSON actor hierarchy + runtime snapshot
 *     GET /metrics — Prometheus text format
 *
 * @psalm-suppress UndefinedClass, MissingDependency
 */
final class TelemetryServer
{
    public function __construct(
        private readonly ActorSystem $system,
        private readonly SwooleRuntime $runtime,
        private readonly string $host = '0.0.0.0',
        private readonly int $port = 9502,
    ) {}

    public function start(): void
    {
        $server = new Server($this->host, $this->port, false, true);

        $server->handle('/status', function (Request $req, Response $res): void {
            $systemSnapshot  = $this->system->snapshot();
            $runtimeSnapshot = $this->runtime->snapshot();

            $res->header('Content-Type', 'application/json');
            $res->end(json_encode([
                'mode' => 'standalone',
                'runtime' => $runtimeSnapshot->toArray(),
                'system' => $systemSnapshot->toArray(),
            ], JSON_THROW_ON_ERROR));
        });

        $server->handle('/metrics', function (Request $req, Response $res): void {
            $collector = new PrometheusCollector();
            $collector->collect($this->system->snapshot(), $this->runtime->snapshot());

            $res->header('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');
            $res->end($collector->render());
        });

        Coroutine::create(static function () use ($server): void {
            $server->start();
        });
    }
}
```

**Step 4: Run integration tests**

```bash
docker compose exec php-swoole vendor/bin/phpunit \
  tests/Integration/Swoole/TelemetryServerTest.php
```

Expected: PASS (2 tests).

**Step 5: Commit**

```bash
git add packages/nexus-observability/src/Http/TelemetryServer.php \
        tests/Integration/Swoole/TelemetryServerTest.php
git commit -m "feat(observability): TelemetryServer with opt-in /status and /metrics HTTP endpoints"
```

---

## Task 9: `WorkerTelemetryPublisher` (nexus-observability)

**Files:**
- Create: `packages/nexus-observability/src/Publisher/WorkerTelemetryPublisher.php`
- Test: `packages/nexus-observability/tests/Unit/Publisher/WorkerTelemetryPublisherTest.php`

**Context:** Created by the user from their worker configure closure. Completely opt-in — the ActorSystem, WorkerNode, and WorkerRunnable have zero knowledge of it. Writes to the shared `Thread\Map` immediately on `start()`, then repeatedly on the configured interval.

The configure closure signature changes from `fn(WorkerNode $node): void` to `fn(WorkerNode $node, Map $sharedDirectory): void` (one-line change in `WorkerRunnable::run()`). This gives the closure access to the Thread\Map needed by the publisher.

**Step 1: Modify `WorkerRunnable::run()` to pass `$this->directory` to the closure**

In `packages/nexus-worker-pool-swoole/src/WorkerRunnable.php`, find:
```php
$configure = opis_unserialize($this->serializedConfigure);
$configure($node);
```

Change to:
```php
$configure = opis_unserialize($this->serializedConfigure);
$configure($node, $this->directory);
```

**Step 2: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Tests\Unit\Publisher;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Observability\Data\WorkerTelemetryEntry;
use Monadial\Nexus\Observability\Publisher\WorkerTelemetryPublisher;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
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
    public function publisher_writes_typed_entry_to_map_immediately(): void
    {
        $map      = new Map();
        $captured = [];

        run(static function () use ($map, &$captured): void {
            $runtime = new SwooleRuntime();
            $system  = ActorSystem::create('worker-0', $runtime);

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

            Coroutine::sleep(0.02); // immediate write, no need to wait for interval

            $captured['raw'] = $map['telemetry:worker-0'] ?? null;

            $system->shutdown(Duration::seconds(1));
        });

        self::assertNotNull($captured['raw']);

        $entry = WorkerTelemetryEntry::fromJson($captured['raw']);

        self::assertSame(0, $entry->workerId);
        self::assertSame('worker-0', $entry->system->systemName);
        self::assertCount(1, $entry->system->actors);
        self::assertSame('/user/orders', $entry->system->actors[0]->path);
        self::assertGreaterThanOrEqual(0, $entry->runtime->coroutineNum);
    }
}
```

**Step 3: Run to confirm failure**

```bash
docker compose exec php-swoole vendor/bin/phpunit \
  packages/nexus-observability/tests/Unit/Publisher/WorkerTelemetryPublisherTest.php
```

Expected: FAIL.

**Step 4: Create `WorkerTelemetryPublisher`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Publisher;

use JsonException;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Observability\Data\WorkerTelemetryEntry;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use Swoole\Thread\Map;

/**
 * @psalm-api
 *
 * Publishes per-worker telemetry to a shared Thread\Map at a regular interval.
 *
 * Completely opt-in — the actor system, WorkerNode, and WorkerRunnable have
 * zero knowledge of this class. Create it from your worker configure closure:
 *
 *     WorkerPoolBootstrap::create($config)
 *         ->withSerializedConfigure(serialize(static function (WorkerNode $node, Map $map): void {
 *             $runtime   = $node->system()->runtime();
 *             assert($runtime instanceof SwooleRuntime);
 *             $publisher = new WorkerTelemetryPublisher($node->workerId(), $node->system(), $runtime, $map);
 *             $publisher->start();
 *             // ... spawn actors ...
 *         }))
 *         ->run();
 *
 * Keys in Thread\Map use the prefix "telemetry:worker-{workerId}" to avoid
 * collision with actor directory entries (which use path-style keys).
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
        private readonly Duration $interval = new Duration(5_000_000_000),
    ) {}

    /**
     * Write immediately, then schedule repeated writes at the configured interval.
     * Non-blocking — uses the runtime's coroutine scheduler.
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
        $entry = new WorkerTelemetryEntry(
            $this->workerId,
            $this->system->snapshot(),
            $this->runtime->snapshot(),
        );

        try {
            $this->map[self::KEY_PREFIX . $this->workerId] = $entry->toJson();
        } catch (JsonException) {
            // Serialization failed — skip this write cycle
        }
    }
}
```

Note: `new Duration(5_000_000_000)` is 5 seconds in nanoseconds. Check `Duration::seconds(5)` works as a default — if not, keep the nanosecond literal.

**Step 5: Run tests**

```bash
docker compose exec php-swoole vendor/bin/phpunit \
  packages/nexus-observability/tests/Unit/Publisher/WorkerTelemetryPublisherTest.php
```

Expected: PASS (1 test).

**Step 6: Commit**

```bash
git add packages/nexus-observability/src/Publisher/WorkerTelemetryPublisher.php \
        packages/nexus-observability/tests/Unit/Publisher/ \
        packages/nexus-worker-pool-swoole/src/WorkerRunnable.php
git commit -m "feat(observability): WorkerTelemetryPublisher writes per-worker snapshots to Thread\Map"
```

---

## Task 10: `WorkerPoolTelemetryServer` (nexus-observability)

**Files:**
- Create: `packages/nexus-observability/src/Http/WorkerPoolTelemetryServer.php`
- Test: `packages/nexus-observability/tests/Unit/Http/WorkerPoolTelemetryServerTest.php`

**Context:** Reads all `"telemetry:worker-*"` keys from a shared `Thread\Map` (obtained from `WorkerPoolHandle::directory()`), deserializes into typed `WorkerTelemetryEntry` objects, aggregates, then serves `/status` (JSON) and `/metrics` (Prometheus text). Completely opt-in — start it from the `withOnStart()` callback.

**Step 1: Write failing unit test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Tests\Unit\Http;

use Monadial\Nexus\Core\Actor\Telemetry\ActorSnapshot;
use Monadial\Nexus\Core\Actor\Telemetry\ActorSystemSnapshot;
use Monadial\Nexus\Observability\Data\WorkerTelemetryEntry;
use Monadial\Nexus\Observability\Http\WorkerPoolTelemetryServer;
use Monadial\Nexus\Runtime\Swoole\Telemetry\SwooleRuntimeSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Thread\Map;

#[CoversClass(WorkerPoolTelemetryServer::class)]
final class WorkerPoolTelemetryServerTest extends TestCase
{
    #[Test]
    public function aggregate_returns_typed_aggregation_from_map(): void
    {
        $map = new Map();

        $makeEntry = static fn(int $id, string $name): WorkerTelemetryEntry =>
            new WorkerTelemetryEntry(
                $id,
                new ActorSystemSnapshot($name, 'ULID', true, [
                    new ActorSnapshot("/user/actor-{$id}", true, $id, 1000, true, []),
                ], $id),
                new SwooleRuntimeSnapshot(10 + $id, 15, 2, 1_000_000, 2_000_000),
            );

        $map['telemetry:worker-0'] = $makeEntry(0, 'nexus-0')->toJson();
        $map['telemetry:worker-1'] = $makeEntry(1, 'nexus-1')->toJson();
        $map['some-directory-key'] = 'ignored'; // actor directory entries are skipped

        $server = new WorkerPoolTelemetryServer($map, host: '127.0.0.1', port: 19510);
        $agg    = $server->aggregate();

        self::assertCount(2, $agg->entries);
        self::assertSame(21, $agg->totalCoroutines); // 10 + 11
        self::assertSame(4, $agg->totalTimers);       // 2 + 2
        self::assertSame(2_000_000, $agg->totalMemoryBytes);
        self::assertSame(1, $agg->totalDeadLetters);  // 0 + 1
    }
}
```

**Step 2: Run to confirm failure**

```bash
docker compose exec php-swoole vendor/bin/phpunit \
  packages/nexus-observability/tests/Unit/Http/WorkerPoolTelemetryServerTest.php
```

Expected: FAIL.

**Step 3: Create `WorkerPoolTelemetryServer`**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Http;

use JsonException;
use Monadial\Nexus\Observability\Data\WorkerPoolAggregation;
use Monadial\Nexus\Observability\Data\WorkerTelemetryEntry;
use Monadial\Nexus\Observability\Prometheus\PrometheusCollector;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Thread\Map;

/**
 * @psalm-api
 *
 * Opt-in HTTP server that aggregates telemetry from all worker threads in a pool.
 *
 * Must be started inside a coroutine context (main thread). Reads entries
 * written by WorkerTelemetryPublisher from a shared Thread\Map.
 *
 * Usage from WorkerPoolBootstrap::withOnStart():
 *     ->withOnStart(function (WorkerPoolHandle $handle): void {
 *         $server = new WorkerPoolTelemetryServer($handle->directory(), port: 9502);
 *         $server->start(); // non-blocking
 *         while (true) { Coroutine::sleep(1); } // keep main thread alive
 *     })
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

    public function start(): void
    {
        $server = new Server($this->host, $this->port, false, true);

        $server->handle('/status', function (Request $req, Response $res): void {
            $agg = $this->aggregate();

            $workers = array_map(
                static fn(WorkerTelemetryEntry $e): array => [
                    'runtime' => $e->runtime->toArray(),
                    'system' => $e->system->toArray(),
                    'worker_id' => $e->workerId,
                ],
                $agg->entries,
            );

            $res->header('Content-Type', 'application/json');
            $res->end(json_encode([
                'aggregates' => [
                    'total_coroutines' => $agg->totalCoroutines,
                    'total_dead_letters' => $agg->totalDeadLetters,
                    'total_memory_bytes' => $agg->totalMemoryBytes,
                    'total_timers' => $agg->totalTimers,
                ],
                'mode' => 'worker-pool',
                'workers' => $workers,
            ], JSON_THROW_ON_ERROR));
        });

        $server->handle('/metrics', function (Request $req, Response $res): void {
            $agg       = $this->aggregate();
            $collector = new PrometheusCollector();

            foreach ($agg->entries as $entry) {
                $collector->collect($entry->system, $entry->runtime, (string) $entry->workerId);
            }

            $res->header('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');
            $res->end($collector->render());
        });

        Coroutine::create(static function () use ($server): void {
            $server->start();
        });
    }

    public function aggregate(): WorkerPoolAggregation
    {
        $entries = [];

        foreach ($this->map as $key => $value) {
            if (!str_starts_with((string) $key, self::KEY_PREFIX)) {
                continue;
            }

            try {
                $entries[] = WorkerTelemetryEntry::fromJson((string) $value);
            } catch (JsonException) {
                // Stale or corrupt entry — skip
            }
        }

        return WorkerPoolAggregation::fromEntries(...$entries);
    }
}
```

**Step 4: Run tests**

```bash
docker compose exec php-swoole vendor/bin/phpunit \
  packages/nexus-observability/tests/Unit/Http/WorkerPoolTelemetryServerTest.php
```

Expected: PASS (1 test).

**Step 5: Commit**

```bash
git add packages/nexus-observability/src/Http/WorkerPoolTelemetryServer.php \
        packages/nexus-observability/tests/Unit/Http/WorkerPoolTelemetryServerTest.php
git commit -m "feat(observability): WorkerPoolTelemetryServer aggregates all workers with Prometheus output"
```

---

## Task 11: Comprehensive logging in `ActorCell`

**Files:**
- Modify: `packages/nexus-core/src/Actor/ActorCell.php`

**Context:** Add structured PSR-3 logging with full context throughout `ActorCell`. The logger is already injected. Use these log levels:
- `DEBUG` — message processing, signal handling, child spawn
- `INFO` — lifecycle transitions (start, stop)
- `WARNING` — supervision directives, dead letters
- `ERROR` — handler exceptions (NexusException, LogicException)
- `CRITICAL` — unchecked exceptions (Error, unexpected Throwable)

Every log entry must include `actor_path` at minimum. Add `message_type`, `exception_class`, `directive`, `child_name`, `signal_type` where relevant.

**Step 1: Add logging to `start()`**

```php
public function start(): void
{
    $this->transitionTo(ActorState::Starting);

    $this->logger->debug('Actor starting', ['actor_path' => (string) $this->actorPath]);

    try {
        $this->resolveWrappers();
    } catch (Throwable $e) {
        $this->logger->error('Actor initialization failed', [
            'actor_path' => (string) $this->actorPath,
            'exception_class' => $e::class,
            'exception_message' => $e->getMessage(),
        ]);
        // ... existing transition + throw
    }

    // ... existing state setup ...

    $this->logger->info('Actor started', ['actor_path' => (string) $this->actorPath]);

    $this->handleSignal(new PreStart());
}
```

**Step 2: Add logging to `initiateStop()`**

```php
public function initiateStop(): void
{
    if ($this->state === ActorState::Stopped || $this->state === ActorState::Stopping) {
        return;
    }

    $this->logger->info('Actor stopping', [
        'actor_path' => (string) $this->actorPath,
        'children_count' => count($this->childrenMap),
    ]);

    // ... existing logic ...

    $this->logger->info('Actor stopped', ['actor_path' => (string) $this->actorPath]);
}
```

**Step 3: Add logging to `processMessage()`**

```php
public function processMessage(Envelope $envelope): void
{
    if ($this->state !== ActorState::Running) {
        return;
    }

    $this->logger->debug('Processing message', [
        'actor_path' => (string) $this->actorPath,
        'message_type' => $envelope->message::class,
        'sender' => (string) $envelope->sender,
    ]);

    // ... existing logic ...
}
```

**Step 4: Add logging to `handleUserMessage()` error paths**

Replace the three `catch` blocks:
```php
} catch (NexusException $e) {
    $this->logger->error('Handler threw NexusException', [
        'actor_path' => (string) $this->actorPath,
        'exception_class' => $e::class,
        'exception_message' => $e->getMessage(),
        'message_type' => $message::class,
    ]);
    $this->decideSupervisedAction($e);
    $this->failPendingAsk(new ActorHandlerException($e->getMessage(), $e));
} catch (Error|LogicException $e) {
    $this->logger->critical('Unchecked exception in handler', [
        'actor_path' => (string) $this->actorPath,
        'exception_class' => $e::class,
        'exception_message' => $e->getMessage(),
        'message_type' => $message::class,
    ]);
    $this->failPendingAsk(new ActorHandlerException($e->getMessage(), $e));
} catch (Throwable $e) {
    $this->logger->critical('Unexpected exception in handler', [
        'actor_path' => (string) $this->actorPath,
        'exception_class' => $e::class,
        'exception_message' => $e->getMessage(),
        'message_type' => $message::class,
    ]);
    $this->failPendingAsk(new ActorHandlerException($e->getMessage(), $e));
}
```

Apply the same pattern to `handleStatefulMessage()` catch blocks.

**Step 5: Add logging to `spawn()`**

```php
$this->logger->debug('Spawning child actor', [
    'actor_path' => (string) $this->actorPath,
    'child_name' => $name,
    'child_path' => (string) $childPath,
]);
```

**Step 6: Add logging to `decideSupervisedAction()`**

```php
private function decideSupervisedAction(NexusException $e): void
{
    $directive = $this->behaviorSupervision !== null
        ? $this->behaviorSupervision->decide($e)
        : null;

    if ($directive !== null && $directive !== Directive::Escalate) {
        $this->logger->warning('Supervision directive applied (behavior-level)', [
            'actor_path' => (string) $this->actorPath,
            'directive' => $directive->name,
            'exception_class' => $e::class,
        ]);

        return;
    }

    $directive = $this->supervision->decide($e);

    $this->logger->warning('Supervision directive applied (actor-level)', [
        'actor_path' => (string) $this->actorPath,
        'directive' => $directive->name,
        'exception_class' => $e::class,
    ]);
}
```

**Step 7: Add logging to `handleSignal()`**

```php
private function handleSignal(Signal $signal): void
{
    $this->logger->debug('Handling lifecycle signal', [
        'actor_path' => (string) $this->actorPath,
        'signal_type' => $signal::class,
    ]);
    // ... existing logic ...
}
```

**Step 8: Run unit tests**

```bash
docker compose exec php vendor/bin/phpunit --testsuite=unit
```

Expected: all passing.

**Step 9: Commit**

```bash
git add packages/nexus-core/src/Actor/ActorCell.php
git commit -m "feat(logging): comprehensive structured logging in ActorCell lifecycle and message processing"
```

---

## Task 12: Comprehensive logging in `ActorSystem`, `WorkerNode`, `WorkerRunnable`

**Files:**
- Modify: `packages/nexus-core/src/Actor/ActorSystem.php`
- Modify: `packages/nexus-worker-pool/src/WorkerNode.php` (add LoggerInterface + logging)
- Modify: `packages/nexus-worker-pool-swoole/src/WorkerRunnable.php`

**Step 1: Add logging to `ActorSystem`**

`ActorSystem` already has `$this->logger`. Add:

In `spawn()`:
```php
$this->logger->info('Spawning top-level actor', [
    'system_name' => $this->systemName,
    'actor_name' => $name,
    'actor_path' => '/user/' . $name,
]);
```

In `spawnAnonymous()`:
```php
$this->logger->debug('Spawning anonymous actor', [
    'system_name' => $this->systemName,
    'actor_name' => $name,
]);
```

In `shutdown()`:
```php
$this->logger->info('Shutting down actor system', [
    'system_name' => $this->systemName,
    'children_count' => count($this->children),
    'timeout_ms' => $timeout->toMillis(),
]);
```

**Step 2: Add `LoggerInterface` to `WorkerNode`**

`WorkerNode` has no logger. Add it as the last constructor parameter:

```php
public function __construct(
    private readonly int $workerId,
    private readonly ActorSystem $system,
    private readonly WorkerTransport $transport,
    private readonly ConsistentHashRing $ring,
    private readonly WorkerDirectory $directory,
    private readonly LoggerInterface $logger = new NullLogger(),
)
```

Add import: `use Psr\Log\LoggerInterface;` and `use Psr\Log\NullLogger;`.

Add logging in `start()`:
```php
public function start(): void
{
    $this->logger->info('Worker node starting transport listener', ['worker_id' => $this->workerId]);
    $this->transport->listen(/* ... existing ... */);
}
```

Add logging in `spawn()`:
```php
if ($ownerWorker === $this->workerId) {
    $this->logger->debug('Spawning actor locally', [
        'worker_id' => $this->workerId,
        'actor_name' => $name,
        'actor_path' => $pathStr,
    ]);
    // ... existing ...
} else {
    $this->logger->debug('Routing actor to remote worker', [
        'worker_id' => $this->workerId,
        'actor_name' => $name,
        'target_worker' => $ownerWorker,
    ]);
    // ... existing ...
}
```

In `askRemote()` timeout callback:
```php
$this->logger->warning('Remote ask timed out', [
    'worker_id' => $this->workerId,
    'target_path' => (string) $targetPath,
    'timeout_ms' => $timeout->toMillis(),
]);
```

**Step 3: Pass logger to `WorkerNode` in `WorkerRunnable`**

In `WorkerRunnable::run()`, change `WorkerNode` construction:
```php
$node = new WorkerNode(
    $workerId,
    $system,
    $transport,
    $ring,
    $directory,
    $logger ?? new NullLogger(),   // ← add logger
);
```

Add import: `use Psr\Log\NullLogger;`

**Step 4: Add startup logging to `WorkerRunnable`**

```php
public function run(): void
{
    $workerId = $this->workerIdCounter->add(1) - 1;

    Coroutine::enableScheduler();

    run(function () use ($workerId): void {
        $logger = $this->createLogger();

        if ($logger !== null) {
            $logger->info('Worker thread starting', [
                'worker_id' => $workerId,
                'system_prefix' => $this->config->systemNamePrefix,
            ]);
        }

        // ... existing setup ...

        if ($logger !== null) {
            $logger->info('Worker thread ready', ['worker_id' => $workerId]);
        }

        $system->run();
    });
}
```

**Step 5: Run all tests**

```bash
docker compose exec php vendor/bin/phpunit --testsuite=unit
docker compose exec php-swoole vendor/bin/phpunit --testsuite=unit-swoole
```

Expected: all passing.

**Step 6: Commit**

```bash
git add packages/nexus-core/src/Actor/ActorSystem.php \
        packages/nexus-worker-pool/src/WorkerNode.php \
        packages/nexus-worker-pool-swoole/src/WorkerRunnable.php
git commit -m "feat(logging): structured logging in ActorSystem, WorkerNode, and WorkerRunnable"
```

---

## Task 13: Full test pass, static analysis, and style

**Step 1: Run all unit suites**

```bash
docker compose exec php vendor/bin/phpunit --testsuite=unit
docker compose exec php-swoole vendor/bin/phpunit --testsuite=unit-swoole
```

Expected: all passing.

**Step 2: Run integration suites**

```bash
docker compose exec php-swoole vendor/bin/phpunit --testsuite=integration-swoole
docker compose exec php-swoole vendor/bin/phpunit --testsuite=integration-workerPool
```

Expected: all passing.

**Step 3: Run Psalm**

```bash
make psalm
```

Fix any level 1 errors. Common patterns in this feature:
- `@psalm-suppress UndefinedClass` on Swoole thread classes in nexus-observability
- `@psalm-suppress MixedAssignment` when reading from `Thread\Map`
- Arrays with string keys not sorted alphabetically — check all `toArray()` methods
- Ensure all `collect()` calls match `@param` types

**Step 4: Run code style**

```bash
make cs-fix
make phpcbf
```

**Step 5: Run Deptrac**

```bash
docker compose exec php vendor/bin/deptrac analyse
```

Expected: no violations. The Observability layer may depend on Core, Runtime, RuntimeSwoole, WorkerPool, WorkerPoolSwoole — all declared in the ruleset added in Task 1.

**Step 6: Commit any fixes**

```bash
git add -A
git commit -m "fix(observability): resolve Psalm, code-style, and Deptrac issues"
```

---

## Notes for the Implementer

### Opt-in wiring example (WorkerPool)

```php
use Monadial\Nexus\Observability\Http\WorkerPoolTelemetryServer;
use Monadial\Nexus\Observability\Publisher\WorkerTelemetryPublisher;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use Monadial\Nexus\WorkerPool\Swoole\WorkerPoolBootstrap;
use Monadial\Nexus\WorkerPool\WorkerPoolConfig;
use Swoole\Thread\Map;

WorkerPoolBootstrap::create(WorkerPoolConfig::withThreads(8))
    ->withSerializedConfigure(serialize(
        static function (WorkerNode $node, Map $sharedDirectory): void {
            // 1. Opt-in telemetry (remove these 4 lines to disable)
            $runtime   = $node->system()->runtime();
            assert($runtime instanceof SwooleRuntime);
            $publisher = new WorkerTelemetryPublisher($node->workerId(), $node->system(), $runtime, $sharedDirectory);
            $publisher->start();

            // 2. Spawn your actors
            $node->spawn(Props::fromFactory(fn() => new OrdersActor()), 'orders');
        }
    ))
    ->withOnStart(function (WorkerPoolHandle $handle): void {
        // Opt-in HTTP (remove to disable)
        $server = new WorkerPoolTelemetryServer($handle->directory(), port: 9502);
        $server->start();

        while (true) {
            Coroutine::sleep(1);
        }
    })
    ->run();
```

### Opt-in wiring example (standalone Swoole)

```php
use Monadial\Nexus\Observability\Http\TelemetryServer;

Co\run(function () {
    $runtime = new SwooleRuntime();
    $system  = ActorSystem::create('my-app', $runtime);

    // Opt-in telemetry (remove to disable)
    $telemetry = new TelemetryServer($system, $runtime, port: 9502);
    $telemetry->start();

    $system->spawn(/* ... */);
    $system->run();
});
```

### Key invariants

- `nexus-core` must not import from any other Nexus package. `ActorSnapshot`, `ActorSystemSnapshot` — zero external Nexus dependencies.
- `nexus-runtime-swoole` may depend on nexus-core and nexus-runtime only.
- `nexus-observability` may depend on all packages listed in deptrac.yaml Observability ruleset.
- All new value objects are `final readonly class`.
- All arrays with string keys must be sorted alphabetically (PHPCS enforces this).
- `ActorCell` constructor call sites: search with `grep -rn "new ActorCell(" packages/ tests/ --include="*.php"` and add `MailboxConfig` as the 4th argument to every match.
