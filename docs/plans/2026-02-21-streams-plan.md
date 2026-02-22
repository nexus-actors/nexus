# Nexus Streams Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build a full Akka Streams-inspired reactive streaming library for Nexus with pull-based demand, PHP 8.5 pipe operator composition, GraphDSL, and materialized values.

**Architecture:** Actor-per-stage — each Source/Flow/Sink materializes as an actor. Pull-based demand protocol flows upstream. Two packages: `nexus-streams-api` (interfaces) and `nexus-streams` (implementation). Blueprints compose via `|>`, then `run($system)` materializes them as supervised actor graphs.

**Tech Stack:** PHP 8.5, Psalm Level 1 generics, PER-CS2.0, PHPUnit 13, existing Nexus Core primitives (Behavior, ActorRef, Props, Mailbox, Supervision).

**Design Doc:** `docs/plans/2026-02-21-streams-design.md`

---

## Phase 1: Package Scaffolding & Protocol

### Task 1: Create nexus-streams package skeleton

**Files:**
- Create: `packages/nexus-streams/composer.json`
- Create: `packages/nexus-streams/src/.gitkeep` (temporary, removed when first real file is added)
- Create: `packages/nexus-streams/tests/Unit/.gitkeep` (temporary)

**Step 1: Create package directory structure**

```bash
mkdir -p packages/nexus-streams/src
mkdir -p packages/nexus-streams/tests/Unit
```

**Step 2: Create composer.json**

Create `packages/nexus-streams/composer.json`:
```json
{
    "name": "nexus-actors/streams",
    "description": "Nexus Streams — reactive streaming with backpressure for PHP actors.",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.5",
        "nexus-actors/core": "^1.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^13.0"
    },
    "autoload": {
        "psr-4": {
            "Monadial\\Nexus\\Streams\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Monadial\\Nexus\\Streams\\Tests\\": "tests/"
        }
    },
    "minimum-stability": "stable",
    "prefer-stable": true
}
```

**Step 3: Register package in root composer.json**

Add to `autoload.psr-4`:
```json
"Monadial\\Nexus\\Streams\\": "packages/nexus-streams/src/"
```

Add to `autoload-dev.psr-4`:
```json
"Monadial\\Nexus\\Streams\\Tests\\": "packages/nexus-streams/tests/"
```

**Step 4: Run composer dump-autoload**

```bash
docker compose exec php composer dump-autoload
```

**Step 5: Commit**

```bash
git add packages/nexus-streams/ composer.json
git commit -m "feat(streams): scaffold nexus-streams package"
```

---

### Task 2: Register package in all config files

**Files:**
- Modify: `deptrac.yaml` — add Streams layer
- Modify: `phpunit.xml` — add unit test suite
- Modify: `psalm.xml` — add project files directory
- Modify: `.php-cs-fixer.dist.php` — add src/tests paths
- Modify: `phpcs.xml` — add src/tests file entries

**Step 1: Add Streams layer to deptrac.yaml**

Add after the Persistence layer definition (line ~58):
```yaml
    - name: Streams
      collectors:
        - type: directory
          value: packages/nexus-streams/src/.*
```

Add to ruleset section:
```yaml
    Streams:
      - Core
```

**Step 2: Add test suite to phpunit.xml**

Add to the `unit` testsuite (after line 19):
```xml
            <directory>packages/nexus-streams/tests/Unit</directory>
```

Add to `<source><include>` (after line 71):
```xml
            <directory>packages/nexus-streams/src</directory>
```

**Step 3: Add to psalm.xml projectFiles**

Add after line 22:
```xml
        <directory name="packages/nexus-streams/src" />
```

**Step 4: Add to .php-cs-fixer.dist.php**

Add to the `->in([...])` array:
```php
        __DIR__ . '/packages/nexus-streams/src',
        __DIR__ . '/packages/nexus-streams/tests',
```

**Step 5: Add to phpcs.xml**

Add after the persistence-doctrine entries:
```xml
    <file>packages/nexus-streams/src</file>
    <file>packages/nexus-streams/tests</file>
```

**Step 6: Verify config is valid**

```bash
docker compose exec php vendor/bin/psalm --no-cache 2>&1 | head -5
docker compose exec php vendor/bin/phpcs --standard=phpcs.xml packages/nexus-streams/ 2>&1 | head -5
```

**Step 7: Commit**

```bash
git add deptrac.yaml phpunit.xml psalm.xml .php-cs-fixer.dist.php phpcs.xml
git commit -m "chore(streams): register nexus-streams in all tooling configs"
```

---

### Task 3: Create demand protocol messages

These are the internal messages between connected stage actors. Users never see them.

**Files:**
- Create: `packages/nexus-streams/src/Internal/Protocol/Request.php`
- Create: `packages/nexus-streams/src/Internal/Protocol/OnNext.php`
- Create: `packages/nexus-streams/src/Internal/Protocol/OnComplete.php`
- Create: `packages/nexus-streams/src/Internal/Protocol/OnError.php`
- Test: `packages/nexus-streams/tests/Unit/Internal/Protocol/ProtocolMessageTest.php`

**Step 1: Write the failing test**

Create `packages/nexus-streams/tests/Unit/Internal/Protocol/ProtocolMessageTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Streams\Tests\Unit\Internal\Protocol;

use Monadial\Nexus\Streams\Internal\Protocol\OnComplete;
use Monadial\Nexus\Streams\Internal\Protocol\OnError;
use Monadial\Nexus\Streams\Internal\Protocol\OnNext;
use Monadial\Nexus\Streams\Internal\Protocol\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(Request::class)]
#[CoversClass(OnNext::class)]
#[CoversClass(OnComplete::class)]
#[CoversClass(OnError::class)]
final class ProtocolMessageTest extends TestCase
{
    #[Test]
    public function requestCarriesDemand(): void
    {
        $request = new Request(16);

        self::assertSame(16, $request->demand);
    }

    #[Test]
    public function onNextCarriesElement(): void
    {
        $next = new OnNext('hello');

        self::assertSame('hello', $next->element);
    }

    #[Test]
    public function onCompleteIsMarkerMessage(): void
    {
        $complete = new OnComplete();

        self::assertInstanceOf(OnComplete::class, $complete);
    }

    #[Test]
    public function onErrorCarriesCause(): void
    {
        $cause = new RuntimeException('stream failed');
        $error = new OnError($cause);

        self::assertSame($cause, $error->cause);
    }
}
```

**Step 2: Run test to verify it fails**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-streams/tests/Unit/Internal/Protocol/ProtocolMessageTest.php
```

Expected: FAIL — classes don't exist yet.

**Step 3: Write the protocol messages**

Create `packages/nexus-streams/src/Internal/Protocol/Request.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Streams\Internal\Protocol;

/**
 * Downstream signals demand for N more elements.
 *
 * @internal
 */
final readonly class Request
{
    public function __construct(public int $demand) {}
}
```

Create `packages/nexus-streams/src/Internal/Protocol/OnNext.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Streams\Internal\Protocol;

/**
 * Upstream pushes the next element downstream.
 *
 * @internal
 */
final readonly class OnNext
{
    public function __construct(public mixed $element) {}
}
```

Create `packages/nexus-streams/src/Internal/Protocol/OnComplete.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Streams\Internal\Protocol;

/**
 * Upstream signals no more elements.
 *
 * @internal
 */
final readonly class OnComplete {}
```

Create `packages/nexus-streams/src/Internal/Protocol/OnError.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Streams\Internal\Protocol;

use Throwable;

/**
 * Upstream signals a stream failure.
 *
 * @internal
 */
final readonly class OnError
{
    public function __construct(public Throwable $cause) {}
}
```

**Step 4: Run test to verify it passes**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-streams/tests/Unit/Internal/Protocol/ProtocolMessageTest.php
```

Expected: PASS (4 tests, 4 assertions).

**Step 5: Run linting + Psalm**

```bash
docker compose exec php vendor/bin/php-cs-fixer fix --dry-run --diff packages/nexus-streams/
docker compose exec php vendor/bin/psalm --no-cache
```

**Step 6: Commit**

```bash
git add packages/nexus-streams/src/Internal/Protocol/ packages/nexus-streams/tests/Unit/Internal/Protocol/
git commit -m "feat(streams): add demand protocol messages (Request, OnNext, OnComplete, OnError)"
```

---

## Phase 2: Core Blueprint Types

### Task 4: Create Shape types

Shapes describe the ports of a stream stage: what inlets and outlets it has.

**Files:**
- Create: `packages/nexus-streams/src/Shape/Shape.php`
- Create: `packages/nexus-streams/src/Shape/SourceShape.php`
- Create: `packages/nexus-streams/src/Shape/FlowShape.php`
- Create: `packages/nexus-streams/src/Shape/SinkShape.php`
- Create: `packages/nexus-streams/src/Shape/ClosedShape.php`
- Create: `packages/nexus-streams/src/Shape/FanInShape.php`
- Create: `packages/nexus-streams/src/Shape/FanOutShape.php`
- Create: `packages/nexus-streams/src/Shape/Inlet.php`
- Create: `packages/nexus-streams/src/Shape/Outlet.php`
- Test: `packages/nexus-streams/tests/Unit/Shape/ShapeTest.php`

**Step 1: Write the failing test**

Create `packages/nexus-streams/tests/Unit/Shape/ShapeTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Streams\Tests\Unit\Shape;

use Monadial\Nexus\Streams\Shape\ClosedShape;
use Monadial\Nexus\Streams\Shape\FlowShape;
use Monadial\Nexus\Streams\Shape\Inlet;
use Monadial\Nexus\Streams\Shape\Outlet;
use Monadial\Nexus\Streams\Shape\SinkShape;
use Monadial\Nexus\Streams\Shape\SourceShape;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SourceShape::class)]
#[CoversClass(SinkShape::class)]
#[CoversClass(FlowShape::class)]
#[CoversClass(ClosedShape::class)]
#[CoversClass(Inlet::class)]
#[CoversClass(Outlet::class)]
final class ShapeTest extends TestCase
{
    #[Test]
    public function sourceShapeHasOutletOnly(): void
    {
        $outlet = Outlet::create('out');
        $shape = new SourceShape($outlet);

        self::assertSame($outlet, $shape->out);
        self::assertSame([], $shape->inlets());
        self::assertSame([$outlet], $shape->outlets());
    }

    #[Test]
    public function sinkShapeHasInletOnly(): void
    {
        $inlet = Inlet::create('in');
        $shape = new SinkShape($inlet);

        self::assertSame($inlet, $shape->in);
        self::assertSame([$inlet], $shape->inlets());
        self::assertSame([], $shape->outlets());
    }

    #[Test]
    public function flowShapeHasInletAndOutlet(): void
    {
        $inlet = Inlet::create('in');
        $outlet = Outlet::create('out');
        $shape = new FlowShape($inlet, $outlet);

        self::assertSame($inlet, $shape->in);
        self::assertSame($outlet, $shape->out);
        self::assertSame([$inlet], $shape->inlets());
        self::assertSame([$outlet], $shape->outlets());
    }

    #[Test]
    public function closedShapeHasNoPorts(): void
    {
        $shape = new ClosedShape();

        self::assertSame([], $shape->inlets());
        self::assertSame([], $shape->outlets());
    }

    #[Test]
    public function inletHasName(): void
    {
        $inlet = Inlet::create('my-input');

        self::assertSame('my-input', $inlet->name);
    }

    #[Test]
    public function outletHasName(): void
    {
        $outlet = Outlet::create('my-output');

        self::assertSame('my-output', $outlet->name);
    }
}
```

**Step 2: Run test to verify it fails**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-streams/tests/Unit/Shape/ShapeTest.php
```

**Step 3: Write the shape types**

Create `packages/nexus-streams/src/Shape/Shape.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Streams\Shape;

/** @psalm-api */
interface Shape
{
    /** @return list<Inlet> */
    public function inlets(): array;

    /** @return list<Outlet> */
    public function outlets(): array;
}
```

Create `packages/nexus-streams/src/Shape/Inlet.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Streams\Shape;

/** @psalm-api */
final readonly class Inlet
{
    private function __construct(public string $name) {}

    public static function create(string $name): self
    {
        return new self($name);
    }
}
```

Create `packages/nexus-streams/src/Shape/Outlet.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Streams\Shape;

/** @psalm-api */
final readonly class Outlet
{
    private function __construct(public string $name) {}

    public static function create(string $name): self
    {
        return new self($name);
    }
}
```

Create `packages/nexus-streams/src/Shape/SourceShape.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Streams\Shape;

/** @psalm-api */
final readonly class SourceShape implements Shape
{
    public function __construct(public Outlet $out) {}

    public function inlets(): array
    {
        return [];
    }

    public function outlets(): array
    {
        return [$this->out];
    }
}
```

Create `packages/nexus-streams/src/Shape/SinkShape.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Streams\Shape;

/** @psalm-api */
final readonly class SinkShape implements Shape
{
    public function __construct(public Inlet $in) {}

    public function inlets(): array
    {
        return [$this->in];
    }

    public function outlets(): array
    {
        return [];
    }
}
```

Create `packages/nexus-streams/src/Shape/FlowShape.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Streams\Shape;

/** @psalm-api */
final readonly class FlowShape implements Shape
{
    public function __construct(
        public Inlet $in,
        public Outlet $out,
    ) {}

    public function inlets(): array
    {
        return [$this->in];
    }

    public function outlets(): array
    {
        return [$this->out];
    }
}
```

Create `packages/nexus-streams/src/Shape/ClosedShape.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Streams\Shape;

/** @psalm-api */
final readonly class ClosedShape implements Shape
{
    public function inlets(): array
    {
        return [];
    }

    public function outlets(): array
    {
        return [];
    }
}
```

Create `packages/nexus-streams/src/Shape/FanOutShape.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Streams\Shape;

/** @psalm-api */
final readonly class FanOutShape implements Shape
{
    /**
     * @param list<Outlet> $outs
     */
    public function __construct(
        public Inlet $in,
        public array $outs,
    ) {}

    public function out(int $index): Outlet
    {
        return $this->outs[$index];
    }

    public function inlets(): array
    {
        return [$this->in];
    }

    public function outlets(): array
    {
        return $this->outs;
    }
}
```

Create `packages/nexus-streams/src/Shape/FanInShape.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Streams\Shape;

/** @psalm-api */
final readonly class FanInShape implements Shape
{
    /**
     * @param list<Inlet> $ins
     */
    public function __construct(
        public array $ins,
        public Outlet $out,
    ) {}

    public function in(int $index): Inlet
    {
        return $this->ins[$index];
    }

    public function inlets(): array
    {
        return $this->ins;
    }

    public function outlets(): array
    {
        return [$this->out];
    }
}
```

**Step 4: Run test to verify it passes**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-streams/tests/Unit/Shape/ShapeTest.php
```

**Step 5: Run linting + Psalm**

```bash
docker compose exec php vendor/bin/php-cs-fixer fix packages/nexus-streams/
docker compose exec php vendor/bin/psalm --no-cache
```

**Step 6: Commit**

```bash
git add packages/nexus-streams/src/Shape/ packages/nexus-streams/tests/Unit/Shape/
git commit -m "feat(streams): add Shape types (Source, Sink, Flow, Closed, FanIn, FanOut)"
```

---

### Task 5: Create Source, Flow, Sink blueprint interfaces

These are the composable blueprint types that describe what to run but don't execute anything.

**Files:**
- Create: `packages/nexus-streams/src/Blueprint/SourceBlueprint.php`
- Create: `packages/nexus-streams/src/Blueprint/FlowBlueprint.php`
- Create: `packages/nexus-streams/src/Blueprint/SinkBlueprint.php`
- Create: `packages/nexus-streams/src/Blueprint/RunnableStream.php`
- Create: `packages/nexus-streams/src/Blueprint/Graph.php`
- Test: `packages/nexus-streams/tests/Unit/Blueprint/BlueprintTest.php`

**Step 1: Write the failing test**

Create `packages/nexus-streams/tests/Unit/Blueprint/BlueprintTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Streams\Tests\Unit\Blueprint;

use Monadial\Nexus\Streams\Blueprint\FlowBlueprint;
use Monadial\Nexus\Streams\Blueprint\SinkBlueprint;
use Monadial\Nexus\Streams\Blueprint\SourceBlueprint;
use Monadial\Nexus\Streams\Shape\FlowShape;
use Monadial\Nexus\Streams\Shape\Inlet;
use Monadial\Nexus\Streams\Shape\Outlet;
use Monadial\Nexus\Streams\Shape\SinkShape;
use Monadial\Nexus\Streams\Shape\SourceShape;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class BlueprintTest extends TestCase
{
    #[Test]
    public function sourceBlueprintHasSourceShape(): void
    {
        self::assertTrue(
            (new \ReflectionClass(SourceBlueprint::class))->isInterface(),
        );
    }

    #[Test]
    public function flowBlueprintHasFlowShape(): void
    {
        self::assertTrue(
            (new \ReflectionClass(FlowBlueprint::class))->isInterface(),
        );
    }

    #[Test]
    public function sinkBlueprintHasSinkShape(): void
    {
        self::assertTrue(
            (new \ReflectionClass(SinkBlueprint::class))->isInterface(),
        );
    }
}
```

**Step 2: Run test to verify it fails**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-streams/tests/Unit/Blueprint/BlueprintTest.php
```

**Step 3: Write the blueprint interfaces**

Create `packages/nexus-streams/src/Blueprint/Graph.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Streams\Blueprint;

use Monadial\Nexus\Streams\Shape\Shape;

/**
 * A graph is a composable stream processing topology with typed ports.
 *
 * @template TShape of Shape
 * @template TMat
 * @psalm-api
 */
interface Graph
{
    /** @return TShape */
    public function shape(): Shape;
}
```

Create `packages/nexus-streams/src/Blueprint/SourceBlueprint.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Streams\Blueprint;

use Monadial\Nexus\Streams\Shape\SourceShape;

/**
 * A source produces elements of type Out.
 *
 * @template Out
 * @template TMat
 * @extends Graph<SourceShape, TMat>
 * @psalm-api
 */
interface SourceBlueprint extends Graph
{
    public function shape(): SourceShape;
}
```

Create `packages/nexus-streams/src/Blueprint/FlowBlueprint.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Streams\Blueprint;

use Monadial\Nexus\Streams\Shape\FlowShape;

/**
 * A flow transforms elements from In to Out.
 *
 * @template In
 * @template Out
 * @template TMat
 * @extends Graph<FlowShape, TMat>
 * @psalm-api
 */
interface FlowBlueprint extends Graph
{
    public function shape(): FlowShape;
}
```

Create `packages/nexus-streams/src/Blueprint/SinkBlueprint.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Streams\Blueprint;

use Monadial\Nexus\Streams\Shape\SinkShape;

/**
 * A sink consumes elements of type In and produces a materialized value.
 *
 * @template In
 * @template TMat
 * @extends Graph<SinkShape, TMat>
 * @psalm-api
 */
interface SinkBlueprint extends Graph
{
    public function shape(): SinkShape;
}
```

Create `packages/nexus-streams/src/Blueprint/RunnableStream.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Streams\Blueprint;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Streams\Shape\ClosedShape;

/**
 * A fully connected stream graph ready to be materialized.
 *
 * @template TMat
 * @extends Graph<ClosedShape, TMat>
 * @psalm-api
 */
interface RunnableStream extends Graph
{
    public function shape(): ClosedShape;

    /**
     * Materialize this stream, spawning actors and returning the materialized value.
     *
     * @return TMat
     */
    public function run(ActorSystem $system): mixed;
}
```

**Step 4: Run test to verify it passes**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-streams/tests/Unit/Blueprint/BlueprintTest.php
```

**Step 5: Run Psalm**

```bash
docker compose exec php vendor/bin/psalm --no-cache
```

**Step 6: Commit**

```bash
git add packages/nexus-streams/src/Blueprint/ packages/nexus-streams/tests/Unit/Blueprint/
git commit -m "feat(streams): add blueprint interfaces (Source, Flow, Sink, RunnableStream, Graph)"
```

---

## Phase 3: Materialized Values

### Task 6: Create Keep combinators and Pair type

**Files:**
- Create: `packages/nexus-streams/src/Mat/Keep.php`
- Create: `packages/nexus-streams/src/Mat/Pair.php`
- Create: `packages/nexus-streams/src/Mat/NotUsed.php`
- Test: `packages/nexus-streams/tests/Unit/Mat/KeepTest.php`

**Step 1: Write the failing test**

Create `packages/nexus-streams/tests/Unit/Mat/KeepTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Streams\Tests\Unit\Mat;

use Monadial\Nexus\Streams\Mat\Keep;
use Monadial\Nexus\Streams\Mat\NotUsed;
use Monadial\Nexus\Streams\Mat\Pair;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Keep::class)]
#[CoversClass(Pair::class)]
#[CoversClass(NotUsed::class)]
final class KeepTest extends TestCase
{
    #[Test]
    public function keepLeftReturnsLeftValue(): void
    {
        $combiner = Keep::left();

        self::assertSame('left', $combiner('left', 'right'));
    }

    #[Test]
    public function keepRightReturnsRightValue(): void
    {
        $combiner = Keep::right();

        self::assertSame('right', $combiner('left', 'right'));
    }

    #[Test]
    public function keepBothReturnsPair(): void
    {
        $combiner = Keep::both();
        $result = $combiner('left', 'right');

        self::assertInstanceOf(Pair::class, $result);
        self::assertSame('left', $result->first);
        self::assertSame('right', $result->second);
    }

    #[Test]
    public function keepNoneReturnsNotUsed(): void
    {
        $combiner = Keep::none();
        $result = $combiner('left', 'right');

        self::assertInstanceOf(NotUsed::class, $result);
    }

    #[Test]
    public function pairDestructures(): void
    {
        $pair = new Pair('a', 42);

        self::assertSame('a', $pair->first);
        self::assertSame(42, $pair->second);
    }

    #[Test]
    public function notUsedIsSingleton(): void
    {
        self::assertSame(NotUsed::instance(), NotUsed::instance());
    }
}
```

**Step 2: Run test to verify it fails**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-streams/tests/Unit/Mat/KeepTest.php
```

**Step 3: Write the mat types**

Create `packages/nexus-streams/src/Mat/Pair.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Streams\Mat;

/**
 * @template A
 * @template B
 * @psalm-api
 */
final readonly class Pair
{
    /**
     * @param A $first
     * @param B $second
     */
    public function __construct(
        public mixed $first,
        public mixed $second,
    ) {}
}
```

Create `packages/nexus-streams/src/Mat/NotUsed.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Streams\Mat;

/**
 * Marker type for materialized values that are not used.
 *
 * @psalm-api
 */
final readonly class NotUsed
{
    private static ?self $instance = null;

    private function __construct() {}

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }
}
```

Create `packages/nexus-streams/src/Mat/Keep.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Streams\Mat;

use Closure;

/**
 * Combinators for choosing which materialized value to keep when connecting stages.
 *
 * @psalm-api
 */
final readonly class Keep
{
    /**
     * Keep the left (upstream) materialized value.
     *
     * @return Closure(mixed, mixed): mixed
     */
    public static function left(): Closure
    {
        return static fn(mixed $left, mixed $right): mixed => $left;
    }

    /**
     * Keep the right (downstream) materialized value.
     *
     * @return Closure(mixed, mixed): mixed
     */
    public static function right(): Closure
    {
        return static fn(mixed $left, mixed $right): mixed => $right;
    }

    /**
     * Keep both materialized values as a Pair.
     *
     * @return Closure(mixed, mixed): Pair
     */
    public static function both(): Closure
    {
        return static fn(mixed $left, mixed $right): Pair => new Pair($left, $right);
    }

    /**
     * Discard both materialized values.
     *
     * @return Closure(mixed, mixed): NotUsed
     */
    public static function none(): Closure
    {
        return static fn(mixed $left, mixed $right): NotUsed => NotUsed::instance();
    }
}
```

**Step 4: Run test to verify it passes**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-streams/tests/Unit/Mat/KeepTest.php
```

**Step 5: Run linting + Psalm**

```bash
docker compose exec php vendor/bin/php-cs-fixer fix packages/nexus-streams/
docker compose exec php vendor/bin/psalm --no-cache
```

**Step 6: Commit**

```bash
git add packages/nexus-streams/src/Mat/ packages/nexus-streams/tests/Unit/Mat/
git commit -m "feat(streams): add materialized value combinators (Keep, Pair, NotUsed)"
```

---

## Phase 4: Linear Source and Sink Implementations

### Task 7: Implement LinearSource — a concrete source blueprint

The first concrete blueprint type. Wraps a stage factory that the materializer will use.

**Files:**
- Create: `packages/nexus-streams/src/Internal/LinearSource.php`
- Create: `packages/nexus-streams/src/Internal/StageFactory.php`
- Test: `packages/nexus-streams/tests/Unit/Internal/LinearSourceTest.php`

**Step 1: Write the failing test**

Create `packages/nexus-streams/tests/Unit/Internal/LinearSourceTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Streams\Tests\Unit\Internal;

use Monadial\Nexus\Streams\Blueprint\SourceBlueprint;
use Monadial\Nexus\Streams\Internal\LinearSource;
use Monadial\Nexus\Streams\Shape\SourceShape;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LinearSource::class)]
final class LinearSourceTest extends TestCase
{
    #[Test]
    public function implementsSourceBlueprint(): void
    {
        $source = LinearSource::fromIterator(
            static fn(): \Iterator => new \ArrayIterator([1, 2, 3]),
        );

        self::assertInstanceOf(SourceBlueprint::class, $source);
        self::assertInstanceOf(SourceShape::class, $source->shape());
    }

    #[Test]
    public function stageFactoryIsAccessible(): void
    {
        $factory = static fn(): \Iterator => new \ArrayIterator([1, 2, 3]);
        $source = LinearSource::fromIterator($factory);

        self::assertNotNull($source->stageFactory());
    }
}
```

**Step 2: Run test to verify it fails**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-streams/tests/Unit/Internal/LinearSourceTest.php
```

**Step 3: Write the StageFactory interface and LinearSource**

Create `packages/nexus-streams/src/Internal/StageFactory.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Streams\Internal;

use Closure;

/**
 * Describes how to create a stage actor's behavior.
 *
 * @internal
 */
final readonly class StageFactory
{
    private function __construct(
        public string $type,
        public Closure $factory,
    ) {}

    public static function source(Closure $factory): self
    {
        return new self('source', $factory);
    }

    public static function flow(Closure $factory): self
    {
        return new self('flow', $factory);
    }

    public static function sink(Closure $factory): self
    {
        return new self('sink', $factory);
    }
}
```

Create `packages/nexus-streams/src/Internal/LinearSource.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Streams\Internal;

use Closure;
use Monadial\Nexus\Streams\Blueprint\SourceBlueprint;
use Monadial\Nexus\Streams\Shape\Outlet;
use Monadial\Nexus\Streams\Shape\SourceShape;

/**
 * Concrete source blueprint backed by a stage factory.
 *
 * @template Out
 * @template TMat
 * @implements SourceBlueprint<Out, TMat>
 * @internal
 */
final readonly class LinearSource implements SourceBlueprint
{
    /**
     * @param StageFactory $stageFactory
     * @param Closure(): TMat $matFactory
     */
    private function __construct(
        private StageFactory $stageFactory,
        private SourceShape $shape,
        private Closure $matFactory,
    ) {}

    /**
     * @template T
     * @param Closure(): \Iterator<int, T> $iteratorFactory
     * @return self<T, \Monadial\Nexus\Streams\Mat\NotUsed>
     */
    public static function fromIterator(Closure $iteratorFactory): self
    {
        return new self(
            StageFactory::source($iteratorFactory),
            new SourceShape(Outlet::create('out')),
            static fn() => \Monadial\Nexus\Streams\Mat\NotUsed::instance(),
        );
    }

    public function shape(): SourceShape
    {
        return $this->shape;
    }

    public function stageFactory(): StageFactory
    {
        return $this->stageFactory;
    }

    /** @return Closure(): TMat */
    public function matFactory(): Closure
    {
        return $this->matFactory;
    }
}
```

**Step 4: Run test to verify it passes**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-streams/tests/Unit/Internal/LinearSourceTest.php
```

**Step 5: Run Psalm**

```bash
docker compose exec php vendor/bin/psalm --no-cache
```

**Step 6: Commit**

```bash
git add packages/nexus-streams/src/Internal/ packages/nexus-streams/tests/Unit/Internal/
git commit -m "feat(streams): add LinearSource blueprint and StageFactory"
```

---

### Task 8: Implement LinearSink and LinearFlow blueprints

**Files:**
- Create: `packages/nexus-streams/src/Internal/LinearSink.php`
- Create: `packages/nexus-streams/src/Internal/LinearFlow.php`
- Create: `packages/nexus-streams/src/Internal/LinearRunnableStream.php`
- Test: `packages/nexus-streams/tests/Unit/Internal/LinearSinkTest.php`
- Test: `packages/nexus-streams/tests/Unit/Internal/LinearFlowTest.php`

Follow the same pattern as Task 7. `LinearFlow` wraps a transform closure + StageFactory. `LinearSink` wraps a consumer closure + StageFactory. `LinearRunnableStream` connects a Source chain to a Sink — it implements `RunnableStream` and delegates `run()` to the `ActorMaterializer`.

These are internal types: the user never instantiates them directly; the DSL factories (`Sources`, `Flows`, `Sinks`) create them.

**Key implementation detail for LinearRunnableStream:**

```php
final readonly class LinearRunnableStream implements RunnableStream
{
    public function __construct(
        private SourceBlueprint $source,
        private SinkBlueprint $sink,
        /** @var list<FlowBlueprint> */
        private array $flows,
        private Closure $matCombiner,
    ) {}

    public function shape(): ClosedShape
    {
        return new ClosedShape();
    }

    public function run(ActorSystem $system): mixed
    {
        $materializer = new ActorMaterializer($system);

        return $materializer->materialize($this);
    }

    // Accessors for the materializer
    public function source(): SourceBlueprint { return $this->source; }
    public function sink(): SinkBlueprint { return $this->sink; }
    /** @return list<FlowBlueprint> */
    public function flows(): array { return $this->flows; }
    public function matCombiner(): Closure { return $this->matCombiner; }
}
```

**Commit message:** `feat(streams): add LinearSink, LinearFlow, and LinearRunnableStream blueprints`

---

### Task 9: Implement Sources DSL — factory methods for pipe composition

This is where the `|>` magic happens. Each factory returns a callable.

**Files:**
- Create: `packages/nexus-streams/src/Dsl/Sources.php`
- Test: `packages/nexus-streams/tests/Unit/Dsl/SourcesTest.php`

**Step 1: Write the failing test**

Create `packages/nexus-streams/tests/Unit/Dsl/SourcesTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Streams\Tests\Unit\Dsl;

use Monadial\Nexus\Streams\Blueprint\SourceBlueprint;
use Monadial\Nexus\Streams\Dsl\Sources;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Sources::class)]
final class SourcesTest extends TestCase
{
    #[Test]
    public function fromCreatesSourceFromIterable(): void
    {
        $source = Sources::from([1, 2, 3]);

        self::assertInstanceOf(SourceBlueprint::class, $source);
    }

    #[Test]
    public function singleCreatesSourceWithOneElement(): void
    {
        $source = Sources::single('hello');

        self::assertInstanceOf(SourceBlueprint::class, $source);
    }

    #[Test]
    public function emptyCreatesEmptySource(): void
    {
        $source = Sources::empty();

        self::assertInstanceOf(SourceBlueprint::class, $source);
    }

    #[Test]
    public function repeatCreatesInfiniteSource(): void
    {
        $source = Sources::repeat('x');

        self::assertInstanceOf(SourceBlueprint::class, $source);
    }

    #[Test]
    public function unfoldCreatesStatefulSource(): void
    {
        $source = Sources::unfold(
            0,
            static fn(int $n) => $n < 5 ? [++$n, $n] : null,
        );

        self::assertInstanceOf(SourceBlueprint::class, $source);
    }
}
```

**Step 2: Run test to verify it fails**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-streams/tests/Unit/Dsl/SourcesTest.php
```

**Step 3: Write Sources DSL**

Create `packages/nexus-streams/src/Dsl/Sources.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Streams\Dsl;

use ArrayIterator;
use Closure;
use EmptyIterator;
use Monadial\Nexus\Streams\Blueprint\SourceBlueprint;
use Monadial\Nexus\Streams\Internal\LinearSource;

/**
 * Factory methods for creating Source blueprints.
 *
 * @psalm-api
 */
final readonly class Sources
{
    /**
     * Create a source from an iterable (finite).
     *
     * @template T
     * @param iterable<T> $elements
     * @return SourceBlueprint<T, \Monadial\Nexus\Streams\Mat\NotUsed>
     */
    public static function from(iterable $elements): SourceBlueprint
    {
        return LinearSource::fromIterator(
            static function () use ($elements): \Iterator {
                if ($elements instanceof \Iterator) {
                    return $elements;
                }

                if ($elements instanceof \IteratorAggregate) {
                    return $elements->getIterator();
                }

                return new ArrayIterator([...$elements]);
            },
        );
    }

    /**
     * Create a source that emits a single element.
     *
     * @template T
     * @param T $element
     * @return SourceBlueprint<T, \Monadial\Nexus\Streams\Mat\NotUsed>
     */
    public static function single(mixed $element): SourceBlueprint
    {
        return LinearSource::fromIterator(
            static fn(): \Iterator => new ArrayIterator([$element]),
        );
    }

    /**
     * Create a source that completes immediately with no elements.
     *
     * @return SourceBlueprint<never, \Monadial\Nexus\Streams\Mat\NotUsed>
     */
    public static function empty(): SourceBlueprint
    {
        return LinearSource::fromIterator(
            static fn(): \Iterator => new EmptyIterator(),
        );
    }

    /**
     * Create a source that repeats a single element infinitely.
     *
     * @template T
     * @param T $element
     * @return SourceBlueprint<T, \Monadial\Nexus\Streams\Mat\NotUsed>
     */
    public static function repeat(mixed $element): SourceBlueprint
    {
        return LinearSource::fromIterator(
            static function () use ($element): \Generator {
                while (true) {
                    yield $element;
                }
            },
        );
    }

    /**
     * Create a source by unfolding state. Completes when the function returns null.
     *
     * @template S
     * @template T
     * @param S $seed
     * @param Closure(S): ?array{S, T} $unfolder Returns [nextState, element] or null to complete
     * @return SourceBlueprint<T, \Monadial\Nexus\Streams\Mat\NotUsed>
     */
    public static function unfold(mixed $seed, Closure $unfolder): SourceBlueprint
    {
        return LinearSource::fromIterator(
            static function () use ($seed, $unfolder): \Generator {
                $state = $seed;

                while (true) {
                    $result = $unfolder($state);

                    if ($result === null) {
                        return;
                    }

                    [$state, $element] = $result;
                    yield $element;
                }
            },
        );
    }
}
```

**Step 4: Run test to verify it passes**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-streams/tests/Unit/Dsl/SourcesTest.php
```

**Step 5: Run linting + Psalm**

```bash
docker compose exec php vendor/bin/php-cs-fixer fix packages/nexus-streams/
docker compose exec php vendor/bin/psalm --no-cache
```

**Step 6: Commit**

```bash
git add packages/nexus-streams/src/Dsl/Sources.php packages/nexus-streams/tests/Unit/Dsl/SourcesTest.php
git commit -m "feat(streams): add Sources DSL (from, single, empty, repeat, unfold)"
```

---

### Task 10: Implement Flows DSL — pipe-compatible transform callables

Each factory returns a `Closure` that accepts a `SourceBlueprint` and returns a new `SourceBlueprint` with the transform appended. This is what makes `|>` work.

**Files:**
- Create: `packages/nexus-streams/src/Dsl/Flows.php`
- Create: `packages/nexus-streams/src/Internal/ConnectedSource.php`
- Test: `packages/nexus-streams/tests/Unit/Dsl/FlowsTest.php`

**Key Design:**

`ConnectedSource` is a SourceBlueprint that wraps an upstream source + a list of flows. When you pipe a Flow into a Source, you get a ConnectedSource. When you pipe another Flow, the flow list grows.

```php
// Sources::from([1,2,3]) returns SourceBlueprint
// Flows::map(fn($x) => $x*2) returns Closure(SourceBlueprint): SourceBlueprint
// The |> operator calls the closure with the source
$result = Sources::from([1,2,3]) |> Flows::map(fn($x) => $x * 2);
// $result is ConnectedSource(upstream=LinearSource, flows=[MapFlow])
```

**Step 1: Write the failing test**

Test that `Flows::map()`, `Flows::filter()`, `Flows::take()` return callables that produce `SourceBlueprint`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Streams\Tests\Unit\Dsl;

use Monadial\Nexus\Streams\Blueprint\SourceBlueprint;
use Monadial\Nexus\Streams\Dsl\Flows;
use Monadial\Nexus\Streams\Dsl\Sources;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Flows::class)]
final class FlowsTest extends TestCase
{
    #[Test]
    public function mapReturnsCallableThatTransformsSource(): void
    {
        $source = Sources::from([1, 2, 3]);
        $mapper = Flows::map(fn(int $x): int => $x * 2);

        $result = $mapper($source);

        self::assertInstanceOf(SourceBlueprint::class, $result);
    }

    #[Test]
    public function filterReturnsCallableThatFiltersSource(): void
    {
        $source = Sources::from([1, 2, 3, 4]);
        $filter = Flows::filter(fn(int $x): bool => $x > 2);

        $result = $filter($source);

        self::assertInstanceOf(SourceBlueprint::class, $result);
    }

    #[Test]
    public function takeReturnsCallableThatLimitsSource(): void
    {
        $source = Sources::from([1, 2, 3, 4, 5]);
        $take = Flows::take(3);

        $result = $take($source);

        self::assertInstanceOf(SourceBlueprint::class, $result);
    }

    #[Test]
    public function chainingMultipleFlowsWorks(): void
    {
        $source = Sources::from([1, 2, 3, 4, 5]);
        $mapped = Flows::map(fn(int $x): int => $x * 2);
        $filtered = Flows::filter(fn(int $x): bool => $x > 4);

        $result = $filtered($mapped($source));

        self::assertInstanceOf(SourceBlueprint::class, $result);
    }
}
```

**Step 2-6: Implement, test, lint, commit**

Implement `ConnectedSource` as a SourceBlueprint that holds an upstream + appended flow. Implement `Flows` with static methods that each return `Closure(SourceBlueprint): SourceBlueprint`. Start with: `map`, `filter`, `take`, `drop`, `scan`, `fold`, `tap`, `grouped`, `distinctUntilChanged`.

**Commit message:** `feat(streams): add Flows DSL with pipe-compatible callables (map, filter, take, etc.)`

---

### Task 11: Implement Sinks DSL — pipe-compatible terminal callables

Each factory returns a `Closure` that accepts a `SourceBlueprint` and returns a `RunnableStream`.

**Files:**
- Create: `packages/nexus-streams/src/Dsl/Sinks.php`
- Test: `packages/nexus-streams/tests/Unit/Dsl/SinksTest.php`

**Key Design:**

```php
// Sinks::foreach(fn($x) => print($x)) returns Closure(SourceBlueprint): RunnableStream<void>
// When piped:
$runnable = Sources::from([1,2,3]) |> Flows::map(fn($x) => $x*2) |> Sinks::foreach(fn($x) => print($x));
// $runnable is LinearRunnableStream
```

Start with: `foreach`, `fold`, `reduce`, `head`, `last`, `seq`, `ignore`, `cancelled`.

**Commit message:** `feat(streams): add Sinks DSL with pipe-compatible callables (foreach, fold, seq, head, etc.)`

---

## Phase 5: Actor Materialization Engine

### Task 12: Implement stage actors — SourceStageActor, FlowStageActor, SinkStageActor

These are the actors that get spawned by the materializer. They implement the demand protocol.

**Files:**
- Create: `packages/nexus-streams/src/Internal/Stage/SourceStageActor.php`
- Create: `packages/nexus-streams/src/Internal/Stage/FlowStageActor.php`
- Create: `packages/nexus-streams/src/Internal/Stage/SinkStageActor.php`
- Test: `packages/nexus-streams/tests/Unit/Internal/Stage/SourceStageActorTest.php`
- Test: `packages/nexus-streams/tests/Unit/Internal/Stage/FlowStageActorTest.php`
- Test: `packages/nexus-streams/tests/Unit/Internal/Stage/SinkStageActorTest.php`

**Key Implementation for SourceStageActor:**

```php
// Uses Behavior::withState to manage:
// - Iterator position
// - Downstream ActorRef
// - Pending demand count

// State shape:
final readonly class SourceStageState
{
    public function __construct(
        public \Iterator $iterator,
        public ActorRef $downstream,
        public int $demand = 0,
        public bool $completed = false,
    ) {}
}

// Behavior:
// On Request(n): increment demand, push up to n elements from iterator
// On iterator exhaustion: send OnComplete downstream, stop self
```

**Key Implementation for FlowStageActor:**

```php
// State shape:
final readonly class FlowStageState
{
    public function __construct(
        public ActorRef $upstream,
        public ActorRef $downstream,
        public Closure $transform,   // the map/filter/etc function
        public string $flowType,      // 'map', 'filter', 'take', etc.
        public int $upstreamDemand = 0,
        public int $downstreamDemand = 0,
    ) {}
}

// Behavior:
// On Request(n) from downstream: forward Request(n) to upstream
// On OnNext(element) from upstream:
//   - Apply transform
//   - If result passes (filter) or is mapped: send OnNext(result) downstream
//   - If filtered out: request 1 more from upstream
// On OnComplete from upstream: forward OnComplete downstream, stop self
// On OnError from upstream: forward OnError downstream, stop self
```

**Key Implementation for SinkStageActor:**

```php
// State shape:
final readonly class SinkStageState
{
    public function __construct(
        public ActorRef $upstream,
        public Closure $handler,      // foreach/fold logic
        public mixed $accumulator,    // for fold sinks
        public int $demand = 0,
    ) {}
}

// Behavior:
// On PreStart signal: send initial Request(BUFFER_SIZE) upstream
// On OnNext(element): call handler, request more if below watermark
// On OnComplete: resolve mat value, stop self
// On OnError(cause): resolve mat value with error, stop self
```

Use `Behavior::setup()` for initial wiring, then `Behavior::withState()` for message processing. Test with StepRuntime for deterministic control.

**Commit message:** `feat(streams): add stage actors (Source, Flow, Sink) with demand protocol`

---

### Task 13: Implement ActorMaterializer

The core engine that takes a `LinearRunnableStream` blueprint and spawns actors.

**Files:**
- Create: `packages/nexus-streams/src/ActorMaterializer.php`
- Create: `packages/nexus-streams/src/MaterializerSettings.php`
- Test: `packages/nexus-streams/tests/Unit/ActorMaterializerTest.php`

**Key Implementation:**

```php
final readonly class ActorMaterializer
{
    public function __construct(
        private ActorSystem $system,
        private MaterializerSettings $settings = new MaterializerSettings(),
    ) {}

    public function materialize(LinearRunnableStream $stream): mixed
    {
        // 1. Spawn StreamSupervisor as parent actor
        // 2. Inside supervisor, spawn stage actors in order:
        //    a. SinkStageActor (needs to know its upstream ref)
        //    b. FlowStageActors from last to first (each needs upstream + downstream)
        //    c. SourceStageActor (needs downstream ref)
        // 3. Wire them: each stage gets refs to its neighbors
        // 4. Sink sends initial Request to kick off the stream
        // 5. Combine materialized values using matCombiner
        // 6. Return combined mat value
    }
}
```

**Materialization Order:**

The materializer spawns actors from sink to source (reverse order) so that each stage knows its downstream when created. Then the sink sends the initial `Request(bufferSize)` to start the pipeline.

```
Spawn order:
1. StreamSupervisor (parent)
2. SinkStageActor (leaf, knows it's the terminal)
3. FlowStageActor[N-1] (downstream = SinkStageActor)
4. FlowStageActor[N-2] (downstream = FlowStageActor[N-1])
5. ...
6. SourceStageActor (downstream = FlowStageActor[0] or SinkStageActor)

Then: SinkStageActor sends Request(16) upstream to start processing.
```

**Commit message:** `feat(streams): add ActorMaterializer — spawns and wires stage actors`

---

### Task 14: Implement StreamSupervisor actor

**Files:**
- Create: `packages/nexus-streams/src/Internal/StreamSupervisor.php`
- Test: `packages/nexus-streams/tests/Unit/Internal/StreamSupervisorTest.php`

The supervisor is a simple actor that:
1. Spawns all stage actors as children
2. Watches them for termination
3. When all stages terminate → stream is complete
4. Applies the configured supervision strategy

**Commit message:** `feat(streams): add StreamSupervisor for stream lifecycle management`

---

## Phase 6: Integration Tests — End-to-End Streams

### Task 15: End-to-end linear stream with Fiber runtime

The first full pipeline test: `Sources::from() |> Flows::map() |> Sinks::seq()`.

**Files:**
- Create: `tests/Integration/Fiber/Streams/LinearStreamTest.php`

**Step 1: Write the integration test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Fiber\Streams;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Monadial\Nexus\Streams\Dsl\Flows;
use Monadial\Nexus\Streams\Dsl\Sinks;
use Monadial\Nexus\Streams\Dsl\Sources;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class LinearStreamTest extends TestCase
{
    #[Test]
    public function sourceMapSinkPipeline(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('stream-test', $runtime);

        $results = [];
        $stream = Sources::from([1, 2, 3, 4, 5])
            |> Flows::map(fn(int $x): int => $x * 2)
            |> Flows::filter(fn(int $x): bool => $x > 4)
            |> Sinks::foreach(function (int $x) use (&$results): void {
                $results[] = $x;
            });

        $stream->run($system);

        $runtime->scheduleOnce(
            Duration::millis(500),
            static fn() => $system->shutdown(Duration::seconds(1)),
        );

        $system->run();

        self::assertSame([6, 8, 10], $results);
    }

    #[Test]
    public function foldSinkProducesMaterializedValue(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('fold-test', $runtime);

        $stream = Sources::from([1, 2, 3, 4, 5])
            |> Sinks::fold(0, fn(int $acc, int $x): int => $acc + $x);

        // The mat value should be the sum: 15
        $sum = $stream->run($system);

        $runtime->scheduleOnce(
            Duration::millis(500),
            static fn() => $system->shutdown(Duration::seconds(1)),
        );

        $system->run();

        self::assertSame(15, $sum);
    }

    #[Test]
    public function emptySourceCompletesImmediately(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('empty-test', $runtime);

        $results = [];
        $stream = Sources::empty()
            |> Sinks::foreach(function (mixed $x) use (&$results): void {
                $results[] = $x;
            });

        $stream->run($system);

        $runtime->scheduleOnce(
            Duration::millis(200),
            static fn() => $system->shutdown(Duration::seconds(1)),
        );

        $system->run();

        self::assertSame([], $results);
    }
}
```

**Step 2: Run — should fail until Task 12-14 are complete**

**Step 3: Debug and fix until all integration tests pass**

**Step 4: Commit**

```bash
git add tests/Integration/Fiber/Streams/
git commit -m "test(streams): add end-to-end Fiber integration tests for linear streams"
```

---

### Task 16: End-to-end stream with Step runtime (deterministic)

Same tests as Task 15 but using StepRuntime for deterministic control. This validates the demand protocol works correctly step-by-step.

**Files:**
- Create: `tests/Integration/Step/Streams/DeterministicStreamTest.php`

**Commit message:** `test(streams): add deterministic Step runtime integration tests for streams`

---

## Phase 7: Extended Flow Operators

### Task 17: Add scan, grouped, groupedWithin, sliding flows

**Files:**
- Modify: `packages/nexus-streams/src/Dsl/Flows.php` — add new factory methods
- Create: `packages/nexus-streams/src/Internal/Stage/ScanFlowStage.php`
- Create: `packages/nexus-streams/src/Internal/Stage/GroupedFlowStage.php`
- Test: `packages/nexus-streams/tests/Unit/Dsl/FlowsScanGroupTest.php`

**Key implementations:**
- `scan($zero, $fn)`: Like fold but emits each intermediate result
- `grouped($size)`: Buffers N elements, emits as array
- `groupedWithin($size, $duration)`: Buffers by size OR time (uses `scheduleOnce` for timeout)
- `sliding($size, $step)`: Sliding window over elements

**Commit message:** `feat(streams): add scan, grouped, groupedWithin, and sliding flow operators`

---

### Task 18: Add throttle, buffer, debounce flows

**Files:**
- Modify: `packages/nexus-streams/src/Dsl/Flows.php`
- Create: `packages/nexus-streams/src/Internal/Stage/ThrottleFlowStage.php`
- Create: `packages/nexus-streams/src/Internal/Stage/BufferFlowStage.php`
- Test: `packages/nexus-streams/tests/Unit/Dsl/FlowsRateControlTest.php`

**Key implementations:**
- `throttle($elements, $per)`: Rate limits using `scheduleOnce` to delay elements
- `buffer($size, $strategy)`: Explicit buffer with overflow strategy (reuses `OverflowStrategy` from Core)
- `debounce($duration)`: Emit element only after quiet period (uses `scheduleOnce`, cancels on new element)

**Commit message:** `feat(streams): add throttle, buffer, and debounce rate control flows`

---

### Task 19: Add error recovery flows

**Files:**
- Modify: `packages/nexus-streams/src/Dsl/Flows.php`
- Test: `packages/nexus-streams/tests/Unit/Dsl/FlowsRecoveryTest.php`

**Key implementations:**
- `recover($fn)`: Catches `OnError`, maps to an element, continues stream
- `recoverWithRetries($attempts, $fn)`: Like recover but retries N times before giving up

**Commit message:** `feat(streams): add error recovery flows (recover, recoverWithRetries)`

---

### Task 20: Add remaining lifecycle flows

**Files:**
- Modify: `packages/nexus-streams/src/Dsl/Flows.php`
- Test: `packages/nexus-streams/tests/Unit/Dsl/FlowsLifecycleTest.php`

Add: `takeWhile`, `drop`, `dropWhile`, `intersperse`, `collect`, `log`, `initialTimeout`, `idleTimeout`, `flatMapConcat`, `flatMapMerge`, `mapAsync`.

Note: `mapAsync`, `flatMapConcat`, and `flatMapMerge` are more complex — each spawns sub-streams or sub-actors. These should be deferred to a later phase if they block progress. Implement the simpler ones first.

**Commit message:** `feat(streams): add lifecycle and advanced flow operators`

---

## Phase 8: GraphDSL

### Task 21: Implement Broadcast and Merge junctions

These are the first fan-out/fan-in stages. Each is an actor.

**Files:**
- Create: `packages/nexus-streams/src/Dsl/Broadcast.php`
- Create: `packages/nexus-streams/src/Dsl/Merge.php`
- Create: `packages/nexus-streams/src/Internal/Stage/BroadcastStageActor.php`
- Create: `packages/nexus-streams/src/Internal/Stage/MergeStageActor.php`
- Test: `packages/nexus-streams/tests/Unit/Dsl/BroadcastTest.php`
- Test: `packages/nexus-streams/tests/Unit/Dsl/MergeTest.php`

**BroadcastStageActor:**
- 1 upstream, N downstreams
- On `OnNext`: copy element to ALL downstreams
- Demand: only request upstream when ALL downstreams have demand (slowest-consumer pacing)

**MergeStageActor:**
- N upstreams, 1 downstream
- On `OnNext` from any upstream: forward downstream
- Demand: distribute downstream demand across upstreams (fair scheduling)
- Complete when ALL upstreams complete

**Commit message:** `feat(streams): add Broadcast and Merge junctions`

---

### Task 22: Implement remaining junctions

**Files:**
- Create: `packages/nexus-streams/src/Dsl/Balance.php` (round-robin fan-out)
- Create: `packages/nexus-streams/src/Dsl/Zip.php` (pair fan-in)
- Create: `packages/nexus-streams/src/Dsl/ZipWith.php` (fn fan-in)
- Create: `packages/nexus-streams/src/Dsl/Concat.php` (sequential fan-in)
- Create: `packages/nexus-streams/src/Dsl/Partition.php` (predicate fan-out)
- Create: `packages/nexus-streams/src/Dsl/Unzip.php` (pair fan-out)
- Create: `packages/nexus-streams/src/Dsl/MergePreferred.php` (priority fan-in)
- Tests for each

**Commit message:** `feat(streams): add Balance, Zip, Concat, Partition, Unzip, MergePreferred junctions`

---

### Task 23: Implement GraphDsl builder

The builder that wires arbitrary topologies.

**Files:**
- Create: `packages/nexus-streams/src/Dsl/GraphDsl.php`
- Create: `packages/nexus-streams/src/Dsl/GraphDsl/Builder.php`
- Create: `packages/nexus-streams/src/Dsl/GraphDsl/PortOps.php`
- Create: `packages/nexus-streams/src/Internal/GraphRunnableStream.php`
- Test: `packages/nexus-streams/tests/Unit/Dsl/GraphDslTest.php`

**Key Implementation:**

```php
final class Builder
{
    /** @var array<string, Shape> registered stages */
    private array $stages = [];

    /** @var array<string, string> outlet → inlet connections */
    private array $connections = [];

    public function add(Graph $stage): Shape
    {
        $id = $this->nextId();
        $this->stages[$id] = $stage;
        return $stage->shape(); // returns shape with ports for wiring
    }

    public function from(Outlet $outlet): PortOps
    {
        return new PortOps($this, $outlet);
    }
}

final class PortOps
{
    public function to(Inlet $inlet): void { /* register connection */ }
    public function via(FlowBlueprint $flow): PortOps { /* add flow inline */ }
}
```

**Validation at build time:**
- All inlets connected to exactly one outlet
- All outlets connected to at most one inlet (fan-out uses Broadcast)
- No cycles (topological sort)
- Closed graphs only (no dangling ports)

**Commit message:** `feat(streams): add GraphDsl builder for arbitrary stream topologies`

---

### Task 24: Extend ActorMaterializer for graph topologies

Modify the materializer to handle `GraphRunnableStream` in addition to `LinearRunnableStream`.

**Files:**
- Modify: `packages/nexus-streams/src/ActorMaterializer.php`
- Test: `packages/nexus-streams/tests/Unit/ActorMaterializerGraphTest.php`

**Key addition:**
- Topological sort of the graph stages
- Spawn actors in reverse topological order (sinks first, sources last)
- Wire refs based on connections map
- Handle fan-in/fan-out actors with multiple upstream/downstream refs

**Commit message:** `feat(streams): extend ActorMaterializer for graph topologies`

---

### Task 25: GraphDsl integration tests

**Files:**
- Create: `tests/Integration/Fiber/Streams/GraphStreamTest.php`

Test the Broadcast/Merge example from the design doc:
```php
//              ┌─► double ─┐
// source → bcast           merge → sink
//              └─► triple ─┘
```

Also test partial graphs (reusable components via `GraphDsl::createFlow()`).

**Commit message:** `test(streams): add integration tests for GraphDsl fan-in/fan-out topologies`

---

## Phase 9: Interactive Sources & Materialized Values

### Task 26: Implement Sources::tick with Cancellable mat value

**Files:**
- Modify: `packages/nexus-streams/src/Dsl/Sources.php`
- Create: `packages/nexus-streams/src/Internal/Stage/TickSourceStageActor.php`
- Test: `packages/nexus-streams/tests/Unit/Dsl/SourcesTickTest.php`

`tick(Duration, element)` emits the element at fixed intervals. Returns `Cancellable` as mat value. Uses `scheduleRepeatedly()` from ActorContext.

**Commit message:** `feat(streams): add Sources::tick with Cancellable materialized value`

---

### Task 27: Implement Sources::queue with SourceQueue mat value

**Files:**
- Create: `packages/nexus-streams/src/SourceQueue.php`
- Modify: `packages/nexus-streams/src/Dsl/Sources.php`
- Test: `packages/nexus-streams/tests/Unit/Dsl/SourcesQueueTest.php`

`queue(bufferSize, strategy)` returns a `SourceQueue<T>` as mat value. The queue allows pushing elements from outside the stream:

```php
interface SourceQueue
{
    public function offer(mixed $element): EnqueueResult;
    public function complete(): void;
    public function fail(Throwable $cause): void;
}
```

**Commit message:** `feat(streams): add Sources::queue with SourceQueue materialized value`

---

### Task 28: Implement Keep combinators on Sink callables

Add `.keepLeft()`, `.keepRight()`, `.keepBoth()` methods to the Sink callables.

**Files:**
- Create: `packages/nexus-streams/src/Internal/SinkCallable.php` — wraps the closure with mat value configuration
- Modify: `packages/nexus-streams/src/Dsl/Sinks.php` — return SinkCallable instead of raw Closure
- Test: `packages/nexus-streams/tests/Unit/Mat/KeepIntegrationTest.php`

**Commit message:** `feat(streams): add Keep combinators to sink callables (keepLeft, keepRight, keepBoth)`

---

## Phase 10: Testing Utilities

### Task 29: Implement TestSink and TestSource

**Files:**
- Create: `packages/nexus-streams/src/TestKit/TestSink.php`
- Create: `packages/nexus-streams/src/TestKit/TestSource.php`
- Test: `packages/nexus-streams/tests/Unit/TestKit/TestSinkTest.php`
- Test: `packages/nexus-streams/tests/Unit/TestKit/TestSourceTest.php`

**TestSink:**
```php
final class TestSink
{
    public static function probe(ActorSystem $system): SinkProbe
    {
        // Returns a Sink callable + probe object
        // The probe records all received elements and signals
    }
}

final class SinkProbe
{
    public function expectNext(mixed $expected): void;
    public function expectComplete(): void;
    public function expectError(\Throwable $expected): void;
    public function toList(): array;
}
```

**TestSource:**
```php
final class TestSource
{
    public static function probe(ActorSystem $system): SourceProbe
    {
        // Returns a Source blueprint + probe object
        // The probe allows pushing elements and signals manually
    }
}

final class SourceProbe
{
    public function sendNext(mixed $element): void;
    public function sendComplete(): void;
    public function sendError(\Throwable $cause): void;
    public function expectRequest(): int; // returns demand
}
```

**Commit message:** `feat(streams): add TestSink and TestSource testing utilities`

---

## Phase 11: Polish & Documentation

### Task 30: Add Streams integration test suite to phpunit.xml

**Files:**
- Modify: `phpunit.xml` — add integration-streams testsuite

```xml
<testsuite name="integration-streams">
    <directory>tests/Integration/Fiber/Streams</directory>
    <directory>tests/Integration/Step/Streams</directory>
</testsuite>
```

Also add a `make test-streams` target to the Makefile.

**Commit message:** `chore(streams): add streams test suite to phpunit.xml and Makefile`

---

### Task 31: Stream exception types

**Files:**
- Create: `packages/nexus-streams/src/Exception/StreamException.php`
- Create: `packages/nexus-streams/src/Exception/StreamTimeoutException.php`
- Create: `packages/nexus-streams/src/Exception/PortNotConnectedException.php`
- Create: `packages/nexus-streams/src/Exception/GraphValidationException.php`
- Test: `packages/nexus-streams/tests/Unit/Exception/StreamExceptionTest.php`

All extend `NexusException` from Core.

**Commit message:** `feat(streams): add stream exception hierarchy`

---

### Task 32: Run full test suite, Psalm, PHPCS, Deptrac

**Step 1:** Run unit tests
```bash
docker compose exec php vendor/bin/phpunit --testsuite=unit
```

**Step 2:** Run Psalm
```bash
docker compose exec php vendor/bin/psalm --no-cache
```

**Step 3:** Run Deptrac
```bash
docker compose exec php php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/deptrac
```

**Step 4:** Run PHPCS
```bash
docker compose exec php vendor/bin/phpcs --standard=phpcs.xml packages/nexus-streams/
```

**Step 5:** Run PHP-CS-Fixer
```bash
docker compose exec php vendor/bin/php-cs-fixer fix --dry-run --diff packages/nexus-streams/
```

**Step 6:** Fix any issues and commit

**Commit message:** `chore(streams): pass full lint, Psalm, Deptrac, and test suite`

---

## Summary

| Phase | Tasks | What it delivers |
|---|---|---|
| 1: Scaffolding & Protocol | 1-3 | Package registered, protocol messages |
| 2: Core Blueprint Types | 4-5 | Shapes, Source/Flow/Sink/RunnableStream interfaces |
| 3: Materialized Values | 6 | Keep, Pair, NotUsed |
| 4: Linear Implementations | 7-11 | Sources/Flows/Sinks DSL, pipe composition |
| 5: Actor Materialization | 12-14 | Stage actors, ActorMaterializer, StreamSupervisor |
| 6: Integration Tests | 15-16 | End-to-end Fiber + Step tests |
| 7: Extended Operators | 17-20 | scan, grouped, throttle, buffer, recover, etc. |
| 8: GraphDSL | 21-25 | Junctions, builder, graph materialization |
| 9: Interactive Sources | 26-28 | tick, queue, Keep combinators |
| 10: Testing Utilities | 29 | TestSink, TestSource |
| 11: Polish | 30-32 | CI integration, exceptions, full validation |

**Minimum viable stream** is after Phase 6 (Tasks 1-16): you can pipe `Sources::from() |> Flows::map() |> Sinks::foreach()` and it runs with backpressure on Fiber and Step runtimes.
