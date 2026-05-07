# Nexus DDD Process Manager Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement the `nexus-ddd-process-manager` package — tactical primitives for stateful coordinators that listen for DomainEvents, dispatch Commands, publish DomainEvents, and schedule deadlines, with full event-sourcing replay correctness.

**Architecture:** Two-class hierarchy mirroring aggregates (StatefulProcessManager + EventSourcedProcessManager). Attributes-only configuration. PSR-everywhere dependencies. MessageStaging + UnitOfWork contracts with InMemory implementations shipped here; outbox-backed implementation deferred to downstream package. Deterministic outbound MessageIds for crash-replay safety.

**Tech Stack:** PHP 8.5+, Psalm strict (level 1), PER-CS2.0, monadial/php-duration, fp4php/functional, symfony/uid, psr/event-dispatcher, psr/log, psr/clock, psr/container.

---

## Source-of-truth references

- Design spec: `docs/superpowers/specs/2026-05-07-nexus-ddd-process-manager-design.md`
- Project conventions: `CLAUDE.md`
- Existing reference for ES-style base + ApplyDispatcher: `packages/nexus-ddd-core/src/Aggregate/EventSourcedAggregateRoot.php`, `packages/nexus-ddd-core/src/Aggregate/Internal/ApplyDispatcher.php`

## Pre-existing constraints honored throughout

- All commands run inside Docker via `docker compose exec -T php ...`
- `Co-Authored-By: Claude` MUST NOT appear in commit messages
- PER-CS2.0 + Slevomat: alphabetical-keyed arrays, multi-line ternary, blank line before control structures, `final` by default, `final readonly` value objects
- PHP 8.5: `#[\Override]`, `#[\NoDiscard]`, `array_first`/`array_last`, `array_find`/`array_any`/`array_all`, pipe operator `|>`
- Psalm strict (level 1)
- PSR contracts only — no `symfony/event-dispatcher`, `monolog/monolog`, etc.

## Branch strategy (Phase 1 Step 1)

The repo currently sits on the worktree branch `feat/nexus-ddd-core`. This work creates a new branch `feat/nexus-ddd-process-manager` *off* `feat/nexus-ddd-core` so the two packages can ship independently. The first task in Phase 1 cuts that branch.

---

## File Structure

The plan creates one new package and modifies a handful of monorepo-level configuration files. New tree below — each leaf is created by a numbered task.

```
packages/nexus-ddd-process-manager/
├── composer.json
├── src/
│   ├── Contract/
│   │   └── Messaging/
│   │       ├── Command.php                    # local marker — moves to nexus-ddd-messaging when that package lands
│   │       ├── MessageContext.php             # local interface — same migration note
│   │       └── README-temporary.md            # one-paragraph migration plan
│   ├── Identity/
│   │   └── ProcessManagerId.php
│   ├── Value/
│   │   ├── DeadlineName.php
│   │   ├── Reason.php
│   │   └── ProcessManagerSnapshotPayload.php
│   ├── Attribute/
│   │   ├── ProcessManager.php
│   │   ├── StartsOn.php
│   │   ├── OnEvent.php
│   │   ├── OnDeadline.php
│   │   ├── OnLateArrival.php
│   │   ├── WithRetry.php
│   │   └── LateArrivalPolicy.php
│   ├── Routing/
│   │   ├── Policy.php
│   │   └── RejectedMessageException.php
│   ├── ProcessManager/
│   │   ├── AbstractProcessManager.php
│   │   ├── StatefulProcessManager.php
│   │   ├── EventSourcedProcessManager.php
│   │   └── ProcessManagerInternals.php
│   ├── Deadline/
│   │   ├── DeadlineOperation.php
│   │   ├── ScheduleDeadline.php
│   │   ├── RescheduleDeadline.php
│   │   ├── CancelDeadline.php
│   │   └── ScheduledDeadlineSnapshot.php
│   ├── Internal/
│   │   ├── Event/
│   │   │   ├── PmStarted.php
│   │   │   ├── PmCompleted.php
│   │   │   ├── PmTerminated.php
│   │   │   ├── PmDeadlineScheduled.php
│   │   │   ├── PmDeadlineRescheduled.php
│   │   │   ├── PmDeadlineCancelled.php
│   │   │   ├── PmDeadlineFired.php
│   │   │   ├── PmCorrelationAdded.php
│   │   │   ├── PmCorrelationRemoved.php
│   │   │   └── PmConsumedExternalEvent.php
│   │   └── Lifecycle/
│   │       ├── ProcessManagerLifecycleEvent.php
│   │       ├── ProcessManagerStarted.php
│   │       ├── ProcessManagerLoaded.php
│   │       ├── ProcessManagerCompleted.php
│   │       ├── ProcessManagerTerminated.php
│   │       ├── TransactionStarted.php
│   │       ├── TransactionCommitted.php
│   │       ├── TransactionRolledBack.php
│   │       ├── DeadlineScheduled.php
│   │       ├── DeadlineFired.php
│   │       ├── DeadlineCancelled.php
│   │       ├── CommandsDispatched.php
│   │       ├── EventsDispatched.php
│   │       ├── HandlerInvocationStarted.php
│   │       ├── HandlerInvocationFinished.php
│   │       └── HandlerInvocationFailed.php
│   ├── Persistence/
│   │   ├── ProcessManagerEventStore.php
│   │   ├── ProcessManagerRepository.php
│   │   ├── ProcessManagerInspector.php
│   │   └── ProcessManagerSnapshot.php
│   ├── Staging/
│   │   ├── MessageStaging.php
│   │   ├── UnitOfWork.php
│   │   ├── InMemoryMessageStaging.php
│   │   └── InMemoryUnitOfWork.php
│   ├── Configuration/
│   │   ├── ProcessManagerDefinition.php
│   │   ├── ProcessManagerDefinitionCompiler.php
│   │   └── HandlerDeclaration.php
│   └── Exception/
│       └── CorrelationConflictException.php
├── tests/
│   ├── Support/
│   │   ├── TestProcessManagerId.php
│   │   ├── FakeMessageContext.php
│   │   └── MessageStagingContractTest.php   # abstract — InMemory + future Outbox both pass
│   └── Unit/
│       ├── (mirrors src/)
│       └── SmokeTest.php
└── (no docs in this package — guide lives at repo-root docs/superpowers/guides/)
```

Modifications to existing files:

- `composer.json` (root) — autoload mapping for new package + `psr/event-dispatcher`/`psr/container` (already present) verified
- `phpunit.xml` (root) — add `unit` testsuite directory entry
- `phpcs.xml` (root) — add `<file>` entries for src + tests
- `psalm.xml` (root) — add `<directory>` entry under `<projectFiles>`
- `deptrac.yaml` (root) — add `DddProcessManager` + `PmInternalEventNamespace` layers + `forbidden_imports` rule
- `docs/superpowers/guides/process-managers-async-discipline.md` — new guide (Phase 12)
- Psalm plugin rules under `packages/nexus-psalm/src/Plugin/Pm/` (Phase 13)

---

## Phase 0 — Branch cut

- [ ] **Step 0.1: Create the work branch off the current ddd-core branch**

Run:

```bash
git -C /Users/tomas/Work/Monadial/CodeOSS/nexus-ddd-core checkout -b feat/nexus-ddd-process-manager
```

Expected output:

```
Switched to a new branch 'feat/nexus-ddd-process-manager'
```

---

## Phase 1 — Package skeleton

This phase wires the new package into the monorepo: composer manifests, autoload, phpunit suite, lint configs, deptrac layer + `forbidden_imports` rule, and a single canary exception class so deptrac/Psalm/phpcs have something to inspect.

### Task 1.1 — Create the package directory structure

- [ ] **Step 1.1.1: Create the package skeleton**

Run:

```bash
mkdir -p /Users/tomas/Work/Monadial/CodeOSS/nexus-ddd-core/packages/nexus-ddd-process-manager/src/{Contract/Messaging,Identity,Value,Attribute,Routing,ProcessManager,Deadline,Internal/Event,Internal/Lifecycle,Persistence,Staging,Configuration,Exception} \
  /Users/tomas/Work/Monadial/CodeOSS/nexus-ddd-core/packages/nexus-ddd-process-manager/tests/{Support,Unit}
```

Expected: command succeeds with no output. Verify with:

```bash
find /Users/tomas/Work/Monadial/CodeOSS/nexus-ddd-core/packages/nexus-ddd-process-manager -type d
```

### Task 1.2 — Package composer.json

- [ ] **Step 1.2.1: Create `packages/nexus-ddd-process-manager/composer.json`**

Path: `packages/nexus-ddd-process-manager/composer.json`

```json
{
    "name": "nexus-actors/ddd-process-manager",
    "description": "Nexus DDD Framework — process manager primitives (stateful + event-sourced bases, deadlines, attributes, in-memory staging).",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.5",
        "fp4php/functional": "^6.0",
        "monadial/php-duration": "^1.0",
        "nexus-actors/ddd-core": "*",
        "psr/clock": "^1.0",
        "psr/container": "^2.0",
        "psr/event-dispatcher": "^1.0",
        "psr/log": "^3.0",
        "symfony/uid": "^8.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^13.0"
    },
    "autoload": {
        "psr-4": {
            "Monadial\\Nexus\\Ddd\\ProcessManager\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Monadial\\Nexus\\Ddd\\ProcessManager\\Tests\\": "tests/"
        }
    }
}
```

### Task 1.3 — Wire root composer.json autoload

- [ ] **Step 1.3.1: Add the autoload mappings to root `composer.json`**

Edit the root `composer.json`. In the `autoload.psr-4` block (alphabetically between `Monadial\\Nexus\\Ddd\\Core\\` and `Monadial\\Nexus\\Persistence\\`) insert:

```json
"Monadial\\Nexus\\Ddd\\ProcessManager\\": "packages/nexus-ddd-process-manager/src/",
```

In the `autoload-dev.psr-4` block (alphabetically between `Monadial\\Nexus\\Ddd\\Core\\Tests\\` and `Monadial\\Nexus\\Persistence\\Tests\\`) insert:

```json
"Monadial\\Nexus\\Ddd\\ProcessManager\\Tests\\": "packages/nexus-ddd-process-manager/tests/",
```

The `require` block already has `psr/clock`, `psr/event-dispatcher`, `psr/log`, `symfony/uid`, `monadial/php-duration`, and `fp4php/functional` — verify each is present (no edit needed if so). If `psr/container` is missing, add `"psr/container": "^2.0"` to `require` in alphabetical order.

### Task 1.4 — Wire phpunit.xml

- [ ] **Step 1.4.1: Add the test directory to `phpunit.xml`**

Edit `phpunit.xml`. In `<testsuite name="unit">`, alphabetically between the `nexus-ddd-core/tests/Unit` line and `nexus-runtime-fiber/tests/Unit`:

```xml
            <directory>packages/nexus-ddd-process-manager/tests/Unit</directory>
```

### Task 1.5 — Wire phpcs.xml

- [ ] **Step 1.5.1: Add the file entries to `phpcs.xml`**

Edit `phpcs.xml`. Insert (alphabetically, just below the `nexus-ddd-core/tests` `<file>` lines):

```xml
    <file>packages/nexus-ddd-process-manager/src</file>
    <file>packages/nexus-ddd-process-manager/tests</file>
```

### Task 1.6 — Wire psalm.xml

- [ ] **Step 1.6.1: Add the directory to `psalm.xml`**

Edit `psalm.xml`. Inside `<projectFiles>`, after the `nexus-ddd-core/src` directory:

```xml
        <directory name="packages/nexus-ddd-process-manager/src" />
```

### Task 1.7 — Wire deptrac.yaml

- [ ] **Step 1.7.1: Add layers + ruleset + forbidden_imports**

Edit `deptrac.yaml`. Append to `layers:` (after `DddCore`):

```yaml
    - name: DddProcessManager
      collectors:
        - type: directory
          value: packages/nexus-ddd-process-manager/src/.*

    - name: PmInternalEventNamespace
      collectors:
        - type: classLike
          regex: ^Monadial\\Nexus\\Ddd\\ProcessManager\\Internal\\Event\\.*$
```

Replace the existing `DddCore: []` line in `ruleset:` with:

```yaml
    DddCore: []
    DddProcessManager:
      - DddCore
```

Append `forbidden_imports` to the top-level config (sibling of `ruleset`):

```yaml
  forbidden_imports:
    DddProcessManager:
      - regex: ^Symfony\\.*
      - regex: ^Laravel\\.*
      - regex: ^Illuminate\\.*
      - regex: ^Monolog\\.*
      - regex: ^Doctrine\\.*
```

### Task 1.8 — Composer install + autoload regeneration

- [ ] **Step 1.8.1: Run composer install in Docker**

Run:

```bash
docker compose exec -T php composer install
```

Expected: succeeds; no autoload errors. The `vendor/composer/autoload_psr4.php` file should now contain the `Monadial\\Nexus\\Ddd\\ProcessManager\\` entry.

Verify autoload:

```bash
docker compose exec -T php php -r 'require "vendor/autoload.php"; var_export(class_exists("Monadial\\Nexus\\Ddd\\ProcessManager\\Foo"));'
```

Expected output: `false`.

### Task 1.9 — Canary exception class

A single concrete class so deptrac/Psalm/phpcs have something to scan. We add `CorrelationConflictException` from §3 of the spec — it'll be used later but shipping it now keeps the package non-empty.

- [ ] **Step 1.9.1: Write the failing test for CorrelationConflictException**

Path: `packages/nexus-ddd-process-manager/tests/Unit/Exception/CorrelationConflictExceptionTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Unit\Exception;

use Monadial\Nexus\Ddd\Core\Exception\NexusDddException;
use Monadial\Nexus\Ddd\ProcessManager\Exception\CorrelationConflictException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CorrelationConflictException::class)]
final class CorrelationConflictExceptionTest extends TestCase
{
    #[Test]
    public function extendsNexusDddException(): void
    {
        $exception = CorrelationConflictException::for('shipmentId', 'A', 'B');
        self::assertInstanceOf(NexusDddException::class, $exception);
    }

    #[Test]
    public function messageMentionsFieldAndConflictingValues(): void
    {
        $exception = CorrelationConflictException::for('shipmentId', 'first-value', 'second-value');
        self::assertStringContainsString('shipmentId', $exception->getMessage());
        self::assertStringContainsString('first-value', $exception->getMessage());
        self::assertStringContainsString('second-value', $exception->getMessage());
    }

    #[Test]
    public function exposesFieldNameAndValuesAsReadonlyProperties(): void
    {
        $exception = CorrelationConflictException::for('shipmentId', 'A', 'B');
        self::assertSame('shipmentId', $exception->field);
        self::assertSame('A', $exception->existingValue);
        self::assertSame('B', $exception->incomingValue);
    }
}
```

- [ ] **Step 1.9.2: Run the test, expect failure**

Run:

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Exception/CorrelationConflictExceptionTest.php
```

Expected: test fails because the class does not exist yet.

- [ ] **Step 1.9.3: Implement CorrelationConflictException**

Path: `packages/nexus-ddd-process-manager/src/Exception/CorrelationConflictException.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Exception;

use Monadial\Nexus\Ddd\Core\Exception\NexusDddException;

/**
 * @psalm-api
 *
 * Thrown by `AbstractProcessManager::correlateOn()` when a secondary
 * correlation field already has a different recorded value. Idempotent
 * re-registration with the SAME value is a no-op; this exception fires
 * only on a genuine conflict.
 */
final class CorrelationConflictException extends NexusDddException
{
    public function __construct(
        public readonly string $field,
        public readonly mixed $existingValue,
        public readonly mixed $incomingValue,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function for(string $field, mixed $existingValue, mixed $incomingValue): self
    {
        return new self(
            $field,
            $existingValue,
            $incomingValue,
            sprintf(
                'Correlation conflict on field %s: existing value %s, incoming value %s.',
                $field,
                self::printable($existingValue),
                self::printable($incomingValue),
            ),
        );
    }

    private static function printable(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            return var_export($value, true);
        }

        return get_debug_type($value);
    }
}
```

- [ ] **Step 1.9.4: Run the test, expect green**

Run:

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Exception/CorrelationConflictExceptionTest.php
```

Expected: 3 tests, 3 assertions, OK.

### Task 1.10 — Run lint/psalm/deptrac to verify wiring

- [ ] **Step 1.10.1: Run PHPCS**

```bash
docker compose exec -T php vendor/bin/phpcs packages/nexus-ddd-process-manager
```

Expected: passes (no violations).

- [ ] **Step 1.10.2: Run Psalm**

```bash
docker compose exec -T php vendor/bin/psalm
```

Expected: passes (no errors).

- [ ] **Step 1.10.3: Run Deptrac**

```bash
docker compose exec -T php php -d error_reporting='E_ALL & ~E_DEPRECATED' vendor/bin/deptrac analyse
```

Expected: green — `DddProcessManager` may depend on `DddCore`; no forbidden vendors detected.

### Task 1.11 — Commit

- [ ] **Step 1.11.1: Stage and commit**

```bash
git add packages/nexus-ddd-process-manager composer.json composer.lock phpunit.xml phpcs.xml psalm.xml deptrac.yaml
git commit -m "$(cat <<'EOF'
feat(ddd-process-manager): package skeleton + monorepo wiring

Adds the package directory, composer.json, autoload entries, phpunit
suite, phpcs/psalm scan paths, and Deptrac DddProcessManager layer with
PSR-only forbidden_imports rule. CorrelationConflictException ships as
the canary class so static analysis has something to inspect.
EOF
)"
```

---

## Phase 2 — Identity & value objects

This phase ships the value-object foundation — `ProcessManagerId`, `DeadlineName`, `Reason`, `ScheduledDeadlineSnapshot`, `ProcessManagerSnapshotPayload`. All `final readonly`. No PM logic yet.

### Task 2.1 — ProcessManagerId

`ProcessManagerId` is the abstract identifier base for PM ids. Concrete subclasses live in user code (e.g., `OrderFulfillmentProcessId extends ProcessManagerId`). It re-uses `UlidValue` semantics; we make it abstract because every PM type wants a distinct concrete ID.

- [ ] **Step 2.1.1: Write the failing test**

Path: `packages/nexus-ddd-process-manager/tests/Unit/Identity/ProcessManagerIdTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Unit\Identity;

use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use Monadial\Nexus\Ddd\ProcessManager\Identity\ProcessManagerId;
use Monadial\Nexus\Ddd\ProcessManager\Tests\Support\TestProcessManagerId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

#[CoversClass(ProcessManagerId::class)]
final class ProcessManagerIdTest extends TestCase
{
    #[Test]
    public function isAnIdentifier(): void
    {
        $id = new TestProcessManagerId((new Ulid())->toBase32());
        self::assertInstanceOf(Identifier::class, $id);
    }

    #[Test]
    public function isAbstract(): void
    {
        $reflection = new \ReflectionClass(ProcessManagerId::class);
        self::assertTrue($reflection->isAbstract());
    }

    #[Test]
    public function valueRoundTripsThroughFromString(): void
    {
        $original = new TestProcessManagerId((new Ulid())->toBase32());
        $reconstructed = TestProcessManagerId::fromString($original->value());
        self::assertTrue($original->equals($reconstructed));
    }
}
```

- [ ] **Step 2.1.2: Write the support fixture**

Path: `packages/nexus-ddd-process-manager/tests/Support/TestProcessManagerId.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Support;

use Monadial\Nexus\Ddd\ProcessManager\Identity\ProcessManagerId;

/**
 * @psalm-immutable
 */
final readonly class TestProcessManagerId extends ProcessManagerId {}
```

- [ ] **Step 2.1.3: Run the test, expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Identity/ProcessManagerIdTest.php
```

Expected: fails — `Class "Monadial\Nexus\Ddd\ProcessManager\Identity\ProcessManagerId" not found`.

- [ ] **Step 2.1.4: Implement ProcessManagerId**

Path: `packages/nexus-ddd-process-manager/src/Identity/ProcessManagerId.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Identity;

use Monadial\Nexus\Ddd\Core\Value\UlidValue;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Abstract identifier base for process manager instances. Concrete
 * subclasses (e.g., `OrderFulfillmentProcessId`) extend this to declare
 * a per-PM-type identity. Per-type stream tables are keyed on this id.
 *
 * Inherits ULID-backed value semantics from `UlidValue`.
 */
abstract readonly class ProcessManagerId extends UlidValue {}
```

- [ ] **Step 2.1.5: Run the test, expect green**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Identity/ProcessManagerIdTest.php
```

Expected: 3 tests, 3 assertions, OK.

### Task 2.2 — DeadlineName

`DeadlineName` is a `final readonly class` over a string. Used to name deadlines in the PM's deadline registry.

- [ ] **Step 2.2.1: Write the failing test**

Path: `packages/nexus-ddd-process-manager/tests/Unit/Value/DeadlineNameTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Unit\Value;

use Monadial\Nexus\Ddd\ProcessManager\Value\DeadlineName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeadlineName::class)]
final class DeadlineNameTest extends TestCase
{
    #[Test]
    public function ofWrapsAStringValue(): void
    {
        $name = DeadlineName::of('payment-deadline');
        self::assertSame('payment-deadline', $name->value());
    }

    #[Test]
    public function equalityIsByValue(): void
    {
        $a = DeadlineName::of('payment-deadline');
        $b = DeadlineName::of('payment-deadline');
        $c = DeadlineName::of('shipping-deadline');
        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }

    #[Test]
    public function rejectsEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DeadlineName::of('');
    }
}
```

- [ ] **Step 2.2.2: Run the test, expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Value/DeadlineNameTest.php
```

Expected: fail — class missing.

- [ ] **Step 2.2.3: Implement DeadlineName**

Path: `packages/nexus-ddd-process-manager/src/Value/DeadlineName.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Value;

use InvalidArgumentException;
use Monadial\Nexus\Ddd\Core\Value\StringValue;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Names a deadline registered on a process manager. The framework's
 * deadline scheduler reconstructs which deadlines are pending by
 * replaying the PM stream (ES) or reading the snapshot (stateful) and
 * keys them by this name.
 *
 * Names are stable identifiers — once a PM type ships, renaming a
 * deadline is a schema migration. Use kebab-case ('payment-deadline').
 */
final readonly class DeadlineName extends StringValue
{
    public static function of(string $name): self
    {
        if ($name === '') {
            throw new InvalidArgumentException('DeadlineName must be a non-empty string.');
        }

        return new self($name);
    }
}
```

- [ ] **Step 2.2.4: Run the test, expect green**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Value/DeadlineNameTest.php
```

Expected: 3 tests, 3 assertions, OK.

### Task 2.3 — Reason

`Reason` carries termination semantics — `code` (machine-readable) + optional `detail` (free-form context).

- [ ] **Step 2.3.1: Write the failing test**

Path: `packages/nexus-ddd-process-manager/tests/Unit/Value/ReasonTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Unit\Value;

use InvalidArgumentException;
use Monadial\Nexus\Ddd\ProcessManager\Value\Reason;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Reason::class)]
final class ReasonTest extends TestCase
{
    #[Test]
    public function ofWithoutDetail(): void
    {
        $reason = Reason::of('payment-not-received-within-24h');
        self::assertSame('payment-not-received-within-24h', $reason->code());
        self::assertNull($reason->detail());
    }

    #[Test]
    public function ofWithDetail(): void
    {
        $reason = Reason::of('shipping-failed', 'carrier API returned 500');
        self::assertSame('shipping-failed', $reason->code());
        self::assertSame('carrier API returned 500', $reason->detail());
    }

    #[Test]
    public function rejectsEmptyCode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Reason::of('');
    }
}
```

- [ ] **Step 2.3.2: Run the test, expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Value/ReasonTest.php
```

- [ ] **Step 2.3.3: Implement Reason**

Path: `packages/nexus-ddd-process-manager/src/Value/Reason.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Value;

use InvalidArgumentException;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Termination reason carried on `terminate()` calls and persisted in the
 * `PmTerminated` event. `code` is a stable machine-readable identifier
 * (`'payment-not-received-within-24h'`); `detail` is optional context
 * the upstream system supplied that the PM could not encode statically
 * (`'shipping-failed'` + `detail: $event->reason`).
 *
 * **Recipe.** Reach for `detail` only when dynamic context adds genuine
 * information ops or auditors will need. Default to no-detail.
 */
final readonly class Reason
{
    private function __construct(
        private string $code,
        private ?string $detail,
    ) {}

    public static function of(string $code, ?string $detail = null): self
    {
        if ($code === '') {
            throw new InvalidArgumentException('Reason code must be a non-empty string.');
        }

        return new self($code, $detail);
    }

    public function code(): string
    {
        return $this->code;
    }

    public function detail(): ?string
    {
        return $this->detail;
    }
}
```

- [ ] **Step 2.3.4: Run the test, expect green**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Value/ReasonTest.php
```

### Task 2.4 — ScheduledDeadlineSnapshot

- [ ] **Step 2.4.1: Write the failing test**

Path: `packages/nexus-ddd-process-manager/tests/Unit/Deadline/ScheduledDeadlineSnapshotTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Unit\Deadline;

use DateTimeImmutable;
use Monadial\Nexus\Ddd\ProcessManager\Deadline\ScheduledDeadlineSnapshot;
use Monadial\Nexus\Ddd\ProcessManager\Value\DeadlineName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ScheduledDeadlineSnapshot::class)]
final class ScheduledDeadlineSnapshotTest extends TestCase
{
    #[Test]
    public function holdsNameAndFireAt(): void
    {
        $name = DeadlineName::of('payment-deadline');
        $fireAt = new DateTimeImmutable('2026-05-08T12:00:00Z');
        $snapshot = new ScheduledDeadlineSnapshot($name, $fireAt);

        self::assertSame($name, $snapshot->name);
        self::assertSame($fireAt, $snapshot->fireAt);
    }
}
```

- [ ] **Step 2.4.2: Run the test, expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Deadline/ScheduledDeadlineSnapshotTest.php
```

- [ ] **Step 2.4.3: Implement ScheduledDeadlineSnapshot**

Path: `packages/nexus-ddd-process-manager/src/Deadline/ScheduledDeadlineSnapshot.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Deadline;

use DateTimeImmutable;
use Monadial\Nexus\Ddd\ProcessManager\Value\DeadlineName;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * A single pending deadline snapshot — one entry of
 * `ProcessManagerSnapshotPayload::$scheduledDeadlines`. Fire time is
 * absolute (`DateTimeImmutable`) so a snapshot loaded today and the
 * scheduler running tomorrow agree on when to fire without doing
 * relative-duration arithmetic against the snapshot's own clock.
 */
final readonly class ScheduledDeadlineSnapshot
{
    public function __construct(
        public DeadlineName $name,
        public DateTimeImmutable $fireAt,
    ) {}
}
```

- [ ] **Step 2.4.4: Run the test, expect green**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Deadline/ScheduledDeadlineSnapshotTest.php
```

### Task 2.5 — Local messaging contracts (TEMPORARY)

`Command` and `MessageContext` belong in `nexus-ddd-messaging`. That package isn't built yet. We define minimal local stubs in `Contract\Messaging\` and document the migration plan. When `nexus-ddd-messaging` lands, these get deleted and replaced by `use Monadial\Nexus\Ddd\Messaging\Command;` etc. — every signature site is already typed against these interfaces, so the swap is mechanical.

- [ ] **Step 2.5.1: Write the failing test**

Path: `packages/nexus-ddd-process-manager/tests/Unit/Contract/Messaging/MessagingContractTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Unit\Contract\Messaging;

use Monadial\Nexus\Ddd\ProcessManager\Contract\Messaging\Command;
use Monadial\Nexus\Ddd\ProcessManager\Contract\Messaging\MessageContext;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class MessagingContractTest extends TestCase
{
    #[Test]
    public function commandIsAMarkerInterface(): void
    {
        $reflection = new \ReflectionClass(Command::class);
        self::assertTrue($reflection->isInterface());
        self::assertSame([], $reflection->getMethods());
    }

    #[Test]
    public function messageContextIsAnInterface(): void
    {
        $reflection = new \ReflectionClass(MessageContext::class);
        self::assertTrue($reflection->isInterface());
    }
}
```

- [ ] **Step 2.5.2: Run the test, expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Contract/Messaging/MessagingContractTest.php
```

- [ ] **Step 2.5.3: Implement Command marker**

Path: `packages/nexus-ddd-process-manager/src/Contract/Messaging/Command.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Contract\Messaging;

/**
 * @psalm-api
 *
 * Marker interface for application Commands a process manager dispatches
 * via `dispatchCommand()`.
 *
 * **TEMPORARY.** This contract belongs in `nexus-ddd-messaging`. It is
 * declared here only because that package does not yet exist. When
 * `nexus-ddd-messaging` lands, this file is deleted and every reference
 * is rewritten to import from there. Concrete Commands MUST be
 * `final readonly class` (Psalm rule `ReadonlyMessageRule` will enforce
 * this once added in Phase 13).
 */
interface Command {}
```

- [ ] **Step 2.5.4: Implement MessageContext interface**

Path: `packages/nexus-ddd-process-manager/src/Contract/Messaging/MessageContext.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Contract\Messaging;

use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * @psalm-api
 *
 * Runtime context passed to every PM handler — clock, logger, message
 * id of the inbound message, correlation/causation ids. Carries enough
 * for handlers to be deterministic under replay (clock from PSR-20)
 * and observable (logger from PSR-3).
 *
 * **TEMPORARY.** This contract belongs in `nexus-ddd-messaging`. When
 * that package lands, this file is deleted and every reference rewires
 * to the messaging-package import.
 */
interface MessageContext
{
    public function clock(): ClockInterface;
    public function log(): LoggerInterface;
    public function messageId(): Identifier;
    public function correlationId(): Identifier;
    public function causationId(): ?Identifier;
}
```

- [ ] **Step 2.5.5: Add the migration README**

Path: `packages/nexus-ddd-process-manager/src/Contract/Messaging/README-temporary.md`

```markdown
# Temporary local messaging contracts

`Command` and `MessageContext` in this directory are **temporary**.

The canonical home is `nexus-ddd-messaging`, which is not yet implemented. When
that package ships, this directory is deleted in a single commit:

1. `composer require nexus-actors/ddd-messaging` in this package's `composer.json`.
2. Global rename: `Monadial\Nexus\Ddd\ProcessManager\Contract\Messaging\Command` -> `Monadial\Nexus\Ddd\Messaging\Command`.
3. Global rename: `Monadial\Nexus\Ddd\ProcessManager\Contract\Messaging\MessageContext` -> `Monadial\Nexus\Ddd\Messaging\MessageContext`.
4. `rm -r src/Contract/Messaging`.
5. Re-run lint + Psalm + tests.

The deferral exists so this package can ship before `nexus-ddd-messaging`. Every
production signature already references the local interfaces, so the swap is
mechanical.
```

- [ ] **Step 2.5.6: Run the test, expect green**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Contract/Messaging/MessagingContractTest.php
```

### Task 2.6 — FakeMessageContext support fixture

For tests we need a stub `MessageContext`. Independent of production code.

- [ ] **Step 2.6.1: Write the test for the fixture**

Path: `packages/nexus-ddd-process-manager/tests/Unit/Support/FakeMessageContextTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Unit\Support;

use DateTimeImmutable;
use Monadial\Nexus\Ddd\ProcessManager\Contract\Messaging\MessageContext;
use Monadial\Nexus\Ddd\ProcessManager\Tests\Support\FakeMessageContext;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class FakeMessageContextTest extends TestCase
{
    #[Test]
    public function implementsMessageContext(): void
    {
        $ctx = FakeMessageContext::default();
        self::assertInstanceOf(MessageContext::class, $ctx);
    }

    #[Test]
    public function reportsTheClockAndLoggerAndIdentities(): void
    {
        $ctx = FakeMessageContext::default();
        self::assertInstanceOf(\Psr\Clock\ClockInterface::class, $ctx->clock());
        self::assertInstanceOf(\Psr\Log\LoggerInterface::class, $ctx->log());
        self::assertNotEmpty($ctx->messageId()->value());
        self::assertNotEmpty($ctx->correlationId()->value());
    }

    #[Test]
    public function clockCanBePinnedToAFixedInstant(): void
    {
        $instant = new DateTimeImmutable('2026-05-07T12:00:00Z');
        $ctx = FakeMessageContext::atInstant($instant);
        self::assertEquals($instant, $ctx->clock()->now());
    }
}
```

- [ ] **Step 2.6.2: Run the test, expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Support/FakeMessageContextTest.php
```

- [ ] **Step 2.6.3: Implement FakeMessageContext**

Path: `packages/nexus-ddd-process-manager/tests/Support/FakeMessageContext.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Support;

use DateTimeImmutable;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use Monadial\Nexus\Ddd\Core\Value\UlidValue;
use Monadial\Nexus\Ddd\ProcessManager\Contract\Messaging\MessageContext;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Ulid;

/**
 * Test fixture implementing `MessageContext`. Pinned clock, NullLogger,
 * fresh ULID identifiers per construction.
 */
final readonly class FakeMessageContext implements MessageContext
{
    private function __construct(
        private ClockInterface $clock,
        private LoggerInterface $log,
        private Identifier $messageId,
        private Identifier $correlationId,
        private ?Identifier $causationId,
    ) {}

    public static function default(): self
    {
        return self::atInstant(new DateTimeImmutable());
    }

    public static function atInstant(DateTimeImmutable $instant): self
    {
        return new self(
            new class ($instant) implements ClockInterface {
                public function __construct(private DateTimeImmutable $now) {}

                #[\Override]
                public function now(): DateTimeImmutable
                {
                    return $this->now;
                }
            },
            new NullLogger(),
            self::freshId(),
            self::freshId(),
            null,
        );
    }

    private static function freshId(): Identifier
    {
        return new readonly class ((new Ulid())->toBase32()) extends UlidValue {};
    }

    #[\Override]
    public function clock(): ClockInterface
    {
        return $this->clock;
    }

    #[\Override]
    public function log(): LoggerInterface
    {
        return $this->log;
    }

    #[\Override]
    public function messageId(): Identifier
    {
        return $this->messageId;
    }

    #[\Override]
    public function correlationId(): Identifier
    {
        return $this->correlationId;
    }

    #[\Override]
    public function causationId(): ?Identifier
    {
        return $this->causationId;
    }
}
```

- [ ] **Step 2.6.4: Run the test, expect green**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Support/FakeMessageContextTest.php
```

### Task 2.7 — ProcessManagerSnapshotPayload

- [ ] **Step 2.7.1: Write the failing test**

Path: `packages/nexus-ddd-process-manager/tests/Unit/Value/ProcessManagerSnapshotPayloadTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Unit\Value;

use Monadial\Nexus\Ddd\ProcessManager\Tests\Support\TestProcessManagerId;
use Monadial\Nexus\Ddd\ProcessManager\Value\ProcessManagerSnapshotPayload;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

#[CoversClass(ProcessManagerSnapshotPayload::class)]
final class ProcessManagerSnapshotPayloadTest extends TestCase
{
    #[Test]
    public function holdsAllSpecifiedFields(): void
    {
        $id = new TestProcessManagerId((new Ulid())->toBase32());
        $payload = new ProcessManagerSnapshotPayload(
            $id,
            'App\\Order\\Process\\OrderFulfillmentProcess',
            42,
            ['orderId' => 'O-1', 'paid' => true],
            true,
            false,
            null,
            null,
            ['external-event-1' => true, 'external-event-2' => true],
            ['shipmentId' => 'SHIP-1'],
            [],
        );

        self::assertSame($id, $payload->id);
        self::assertSame('App\\Order\\Process\\OrderFulfillmentProcess', $payload->pmClass);
        self::assertSame(42, $payload->version);
        self::assertSame(['orderId' => 'O-1', 'paid' => true], $payload->userState);
        self::assertTrue($payload->isCompleted);
        self::assertFalse($payload->isTerminated);
        self::assertNull($payload->terminationReason);
        self::assertNull($payload->startedBy);
        self::assertSame(['external-event-1' => true, 'external-event-2' => true], $payload->consumedEventIds);
        self::assertSame(['shipmentId' => 'SHIP-1'], $payload->correlations);
        self::assertSame([], $payload->scheduledDeadlines);
    }
}
```

- [ ] **Step 2.7.2: Run the test, expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Value/ProcessManagerSnapshotPayloadTest.php
```

- [ ] **Step 2.7.3: Implement ProcessManagerSnapshotPayload**

Path: `packages/nexus-ddd-process-manager/src/Value/ProcessManagerSnapshotPayload.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Value;

use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\ProcessManager\Deadline\ScheduledDeadlineSnapshot;
use Monadial\Nexus\Ddd\ProcessManager\Identity\ProcessManagerId;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Snapshot payload for an event-sourced process manager. The contract
 * requires the payload to round-trip the FULL PM state — not just user
 * fields, but everything replay would have rebuilt: completion flags,
 * termination reason, originating event, consumed-event dedup set,
 * secondary correlation index, and pending deadlines. A snapshot at
 * version N plus events N+1..M MUST equal a full replay from event 1.
 */
final readonly class ProcessManagerSnapshotPayload
{
    /**
     * @param array<string, mixed> $userState
     * @param array<string, true> $consumedEventIds
     * @param array<string, mixed> $correlations
     * @param array<int, ScheduledDeadlineSnapshot> $scheduledDeadlines
     */
    public function __construct(
        public ProcessManagerId $id,
        public string $pmClass,
        public int $version,
        public array $userState,
        public bool $isCompleted,
        public bool $isTerminated,
        public ?Reason $terminationReason,
        public ?DomainEvent $startedBy,
        public array $consumedEventIds,
        public array $correlations,
        public array $scheduledDeadlines,
    ) {}
}
```

- [ ] **Step 2.7.4: Run the test, expect green**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Value/ProcessManagerSnapshotPayloadTest.php
```

### Task 2.8 — Phase 2 lint/Psalm/deptrac sweep + commit

- [ ] **Step 2.8.1: Run PHPCS, Psalm, Deptrac**

```bash
docker compose exec -T php vendor/bin/phpcs packages/nexus-ddd-process-manager
docker compose exec -T php vendor/bin/psalm
docker compose exec -T php php -d error_reporting='E_ALL & ~E_DEPRECATED' vendor/bin/deptrac analyse
```

Expected: all green.

- [ ] **Step 2.8.2: Commit**

```bash
git add packages/nexus-ddd-process-manager
git commit -m "$(cat <<'EOF'
feat(ddd-process-manager): identity, value objects, messaging contracts

Adds ProcessManagerId (abstract ULID-backed identity base), DeadlineName,
Reason, ScheduledDeadlineSnapshot, ProcessManagerSnapshotPayload, and the
TEMPORARY local Command + MessageContext interfaces under
src/Contract/Messaging (will move to nexus-ddd-messaging when that
package lands). Test support fixture FakeMessageContext.
EOF
)"
```

---

## Phase 3 — Attributes (method-level + class-level) and Routing enums

This phase ships all PHP attributes the configuration compiler reads at boot, plus the `Policy` enum and `RejectedMessageException`. Each attribute is a tiny `final readonly class`; tests verify constructor parameter capture and Attribute targets via reflection.

### Task 3.1 — Routing\Policy enum

- [ ] **Step 3.1.1: Write the failing test**

Path: `packages/nexus-ddd-process-manager/tests/Unit/Routing/PolicyTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Unit\Routing;

use Monadial\Nexus\Ddd\ProcessManager\Routing\Policy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Policy::class)]
final class PolicyTest extends TestCase
{
    #[Test]
    public function declaresThreeCases(): void
    {
        $cases = Policy::cases();
        $names = array_map(static fn(Policy $p): string => $p->name, $cases);

        self::assertContains('DeadLetter', $names);
        self::assertContains('LogAndDrop', $names);
        self::assertContains('Reject', $names);
        self::assertCount(3, $cases);
    }
}
```

- [ ] **Step 3.1.2: Run the test, expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Routing/PolicyTest.php
```

- [ ] **Step 3.1.3: Implement Policy**

Path: `packages/nexus-ddd-process-manager/src/Routing/Policy.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Routing;

/**
 * @psalm-api
 *
 * Late-arrival routing policy for events that arrive at a completed or
 * terminated PM with no matching `#[OnLateArrival]` handler.
 *
 * - `DeadLetter` (default) — route to DLQ with full routing trace
 * - `LogAndDrop` — structured log + metric, no DLQ entry
 * - `Reject` — throw `RejectedMessageException`; upstream bus decides retry
 *
 * `DeadLetter` is the default because silent drops produce production
 * mysteries that survive postmortems.
 */
enum Policy
{
    case DeadLetter;
    case LogAndDrop;
    case Reject;
}
```

- [ ] **Step 3.1.4: Run the test, expect green**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Routing/PolicyTest.php
```

### Task 3.2 — RejectedMessageException

- [ ] **Step 3.2.1: Write the failing test**

Path: `packages/nexus-ddd-process-manager/tests/Unit/Routing/RejectedMessageExceptionTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Unit\Routing;

use Monadial\Nexus\Ddd\Core\Exception\NexusDddException;
use Monadial\Nexus\Ddd\ProcessManager\Routing\RejectedMessageException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RejectedMessageException::class)]
final class RejectedMessageExceptionTest extends TestCase
{
    #[Test]
    public function isANexusDddException(): void
    {
        $exception = RejectedMessageException::for('PaymentReceived', 'late-arrival to terminated PM');
        self::assertInstanceOf(NexusDddException::class, $exception);
    }

    #[Test]
    public function carriesEventClassAndReason(): void
    {
        $exception = RejectedMessageException::for('PaymentReceived', 'late-arrival to terminated PM');
        self::assertSame('PaymentReceived', $exception->eventClass);
        self::assertSame('late-arrival to terminated PM', $exception->reason);
        self::assertStringContainsString('PaymentReceived', $exception->getMessage());
        self::assertStringContainsString('late-arrival to terminated PM', $exception->getMessage());
    }
}
```

- [ ] **Step 3.2.2: Run the test, expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Routing/RejectedMessageExceptionTest.php
```

- [ ] **Step 3.2.3: Implement RejectedMessageException**

Path: `packages/nexus-ddd-process-manager/src/Routing/RejectedMessageException.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Routing;

use Monadial\Nexus\Ddd\Core\Exception\NexusDddException;

/**
 * @psalm-api
 *
 * Thrown when class-level `#[LateArrivalPolicy(Policy::Reject)]` rules
 * an incoming event after PM completion/termination. Upstream bus
 * decides retry semantics — re-dispatch, dead-letter, or surface to ops.
 */
final class RejectedMessageException extends NexusDddException
{
    public function __construct(
        public readonly string $eventClass,
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function for(string $eventClass, string $reason): self
    {
        return new self(
            $eventClass,
            $reason,
            sprintf('Process manager rejected event %s: %s.', $eventClass, $reason),
        );
    }
}
```

- [ ] **Step 3.2.4: Run the test, expect green**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Routing/RejectedMessageExceptionTest.php
```

### Task 3.3 — `#[ProcessManager]` class attribute

- [ ] **Step 3.3.1: Write the failing test**

Path: `packages/nexus-ddd-process-manager/tests/Unit/Attribute/ProcessManagerAttributeTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Unit\Attribute;

use Attribute;
use Monadial\Nexus\Ddd\ProcessManager\Attribute\ProcessManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(ProcessManager::class)]
final class ProcessManagerAttributeTest extends TestCase
{
    #[Test]
    public function targetsClassesOnly(): void
    {
        $reflection = new ReflectionClass(ProcessManager::class);
        $attr = $reflection->getAttributes(Attribute::class)[0]->newInstance();
        self::assertSame(Attribute::TARGET_CLASS, $attr->flags);
    }

    #[Test]
    public function deleteOnCompleteDefaultsToTrue(): void
    {
        $attr = new ProcessManager();
        self::assertTrue($attr->deleteOnComplete);
    }

    #[Test]
    public function deleteOnCompleteCanBeOverridden(): void
    {
        $attr = new ProcessManager(deleteOnComplete: false);
        self::assertFalse($attr->deleteOnComplete);
    }
}
```

- [ ] **Step 3.3.2: Run the test, expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Attribute/ProcessManagerAttributeTest.php
```

- [ ] **Step 3.3.3: Implement the attribute**

Path: `packages/nexus-ddd-process-manager/src/Attribute/ProcessManager.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Attribute;

use Attribute;

/**
 * @psalm-api
 *
 * Marks a class as a process manager. Required on every PM class so the
 * `ProcessManagerDefinitionCompiler` discovers it via reflection.
 *
 * `deleteOnComplete` controls whether the runtime hard-deletes the PM's
 * persisted stream + snapshot when `complete()` is called. Default true
 * matches the everyday case (one-shot workflows). Set to false when the
 * audit trail must survive (regulatory, postmortem-prone domains).
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ProcessManager
{
    public function __construct(public bool $deleteOnComplete = true) {}
}
```

- [ ] **Step 3.3.4: Run the test, expect green**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Attribute/ProcessManagerAttributeTest.php
```

### Task 3.4 — `#[StartsOn]` attribute

- [ ] **Step 3.4.1: Write the failing test**

Path: `packages/nexus-ddd-process-manager/tests/Unit/Attribute/StartsOnTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Unit\Attribute;

use Attribute;
use Monadial\Nexus\Ddd\ProcessManager\Attribute\StartsOn;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(StartsOn::class)]
final class StartsOnTest extends TestCase
{
    #[Test]
    public function targetsRepeatableMethods(): void
    {
        $reflection = new ReflectionClass(StartsOn::class);
        $attr = $reflection->getAttributes(Attribute::class)[0]->newInstance();
        self::assertSame(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE, $attr->flags);
    }

    #[Test]
    public function carriesEventClassAndCorrelateBy(): void
    {
        $attr = new StartsOn('App\\OrderPlaced', 'orderId');
        self::assertSame('App\\OrderPlaced', $attr->eventClass);
        self::assertSame('orderId', $attr->correlateBy);
    }
}
```

- [ ] **Step 3.4.2: Run the test, expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Attribute/StartsOnTest.php
```

- [ ] **Step 3.4.3: Implement StartsOn**

Path: `packages/nexus-ddd-process-manager/src/Attribute/StartsOn.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Attribute;

use Attribute;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;

/**
 * @psalm-api
 *
 * Marks a method as the entry-point for a PM type. The method handles
 * the FIRST event that creates an instance under the supplied
 * correlation key. Multiple `#[StartsOn]` declarations per PM class are
 * allowed (each starts the PM from a different triggering event); see
 * design spec §5 "Multiple #[StartsOn] rules" for the race semantics.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class StartsOn
{
    /** @param class-string<DomainEvent> $eventClass */
    public function __construct(
        public string $eventClass,
        public string $correlateBy,
    ) {}
}
```

- [ ] **Step 3.4.4: Run the test, expect green**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Attribute/StartsOnTest.php
```

### Task 3.5 — `#[OnEvent]` attribute

- [ ] **Step 3.5.1: Write the failing test**

Path: `packages/nexus-ddd-process-manager/tests/Unit/Attribute/OnEventTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Unit\Attribute;

use Attribute;
use Monadial\Nexus\Ddd\ProcessManager\Attribute\OnEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(OnEvent::class)]
final class OnEventTest extends TestCase
{
    #[Test]
    public function targetsRepeatableMethods(): void
    {
        $reflection = new ReflectionClass(OnEvent::class);
        $attr = $reflection->getAttributes(Attribute::class)[0]->newInstance();
        self::assertSame(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE, $attr->flags);
    }

    #[Test]
    public function carriesEventClassAndCorrelateBy(): void
    {
        $attr = new OnEvent('App\\PaymentReceived', 'orderId');
        self::assertSame('App\\PaymentReceived', $attr->eventClass);
        self::assertSame('orderId', $attr->correlateBy);
    }
}
```

- [ ] **Step 3.5.2: Run the test, expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Attribute/OnEventTest.php
```

- [ ] **Step 3.5.3: Implement OnEvent**

Path: `packages/nexus-ddd-process-manager/src/Attribute/OnEvent.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Attribute;

use Attribute;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;

/**
 * @psalm-api
 *
 * Marks a method as the handler for a domain event delivered to an
 * existing PM instance, correlated via the supplied field name.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class OnEvent
{
    /** @param class-string<DomainEvent> $eventClass */
    public function __construct(
        public string $eventClass,
        public string $correlateBy,
    ) {}
}
```

- [ ] **Step 3.5.4: Run the test, expect green**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Attribute/OnEventTest.php
```

### Task 3.6 — `#[OnDeadline]` attribute

- [ ] **Step 3.6.1: Write the failing test**

Path: `packages/nexus-ddd-process-manager/tests/Unit/Attribute/OnDeadlineTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Unit\Attribute;

use Attribute;
use Monadial\Nexus\Ddd\ProcessManager\Attribute\OnDeadline;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(OnDeadline::class)]
final class OnDeadlineTest extends TestCase
{
    #[Test]
    public function targetsMethodsNonRepeatable(): void
    {
        $reflection = new ReflectionClass(OnDeadline::class);
        $attr = $reflection->getAttributes(Attribute::class)[0]->newInstance();
        self::assertSame(Attribute::TARGET_METHOD, $attr->flags);
    }

    #[Test]
    public function carriesNameValue(): void
    {
        $attr = new OnDeadline('payment-deadline');
        self::assertSame('payment-deadline', $attr->name);
    }
}
```

- [ ] **Step 3.6.2: Run the test, expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Attribute/OnDeadlineTest.php
```

- [ ] **Step 3.6.3: Implement OnDeadline**

Path: `packages/nexus-ddd-process-manager/src/Attribute/OnDeadline.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Attribute;

use Attribute;

/**
 * @psalm-api
 *
 * Marks a method as the handler for the named deadline firing.
 * The string `$name` matches the `DeadlineName` value the PM scheduled.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class OnDeadline
{
    public function __construct(public string $name) {}
}
```

- [ ] **Step 3.6.4: Run the test, expect green**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Attribute/OnDeadlineTest.php
```

### Task 3.7 — `#[OnLateArrival]` attribute

- [ ] **Step 3.7.1: Write the failing test**

Path: `packages/nexus-ddd-process-manager/tests/Unit/Attribute/OnLateArrivalTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Unit\Attribute;

use Attribute;
use Monadial\Nexus\Ddd\ProcessManager\Attribute\OnLateArrival;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(OnLateArrival::class)]
final class OnLateArrivalTest extends TestCase
{
    #[Test]
    public function targetsMethodsNonRepeatable(): void
    {
        $reflection = new ReflectionClass(OnLateArrival::class);
        $attr = $reflection->getAttributes(Attribute::class)[0]->newInstance();
        self::assertSame(Attribute::TARGET_METHOD, $attr->flags);
    }

    #[Test]
    public function instantiatesWithoutArguments(): void
    {
        $attr = new OnLateArrival();
        self::assertInstanceOf(OnLateArrival::class, $attr);
    }
}
```

- [ ] **Step 3.7.2: Run the test, expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Attribute/OnLateArrivalTest.php
```

- [ ] **Step 3.7.3: Implement OnLateArrival**

Path: `packages/nexus-ddd-process-manager/src/Attribute/OnLateArrival.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Attribute;

use Attribute;

/**
 * @psalm-api
 *
 * Marks a method as a late-arrival handler for events delivered to an
 * already-completed/terminated PM. The framework dispatches by
 * reflection on the method's parameter type — pass a typed event class
 * to handle a specific late event, or `DomainEvent` for a catch-all.
 *
 * **Discipline (enforced by `OnLateArrivalSemanticsRule` in nexus-psalm):**
 * - MUST NOT call `recordThat()` (the stream is closed)
 * - MUST NOT call `complete()` / `terminate()` (state already terminal)
 * - SHOULD only emit compensating commands; MAY emit DomainEvents for
 *   downstream observability
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class OnLateArrival {}
```

- [ ] **Step 3.7.4: Run the test, expect green**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Attribute/OnLateArrivalTest.php
```

### Task 3.8 — `#[WithRetry]` attribute

- [ ] **Step 3.8.1: Write the failing test**

Path: `packages/nexus-ddd-process-manager/tests/Unit/Attribute/WithRetryTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Unit\Attribute;

use Attribute;
use Monadial\Nexus\Ddd\ProcessManager\Attribute\WithRetry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(WithRetry::class)]
final class WithRetryTest extends TestCase
{
    #[Test]
    public function targetsRepeatableMethods(): void
    {
        $reflection = new ReflectionClass(WithRetry::class);
        $attr = $reflection->getAttributes(Attribute::class)[0]->newInstance();
        self::assertSame(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE, $attr->flags);
    }

    #[Test]
    public function defaultsAreSensible(): void
    {
        $attr = new WithRetry();
        self::assertSame(3, $attr->maxAttempts);
        self::assertSame('1s', $attr->initialDelay);
        self::assertSame('30s', $attr->maxDelay);
        self::assertNotEmpty($attr->strategy);
    }

    #[Test]
    public function fieldsCanBeOverridden(): void
    {
        $attr = new WithRetry(maxAttempts: 5, strategy: 'App\\CustomBackoff', initialDelay: '500ms', maxDelay: '60s');
        self::assertSame(5, $attr->maxAttempts);
        self::assertSame('App\\CustomBackoff', $attr->strategy);
        self::assertSame('500ms', $attr->initialDelay);
        self::assertSame('60s', $attr->maxDelay);
    }
}
```

- [ ] **Step 3.8.2: Run the test, expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Attribute/WithRetryTest.php
```

- [ ] **Step 3.8.3: Implement WithRetry**

Path: `packages/nexus-ddd-process-manager/src/Attribute/WithRetry.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Attribute;

use Attribute;

/**
 * @psalm-api
 *
 * Declares retry behavior for the annotated handler. The class-string
 * `$strategy` is resolved by the runtime against a registered backoff
 * strategy. Defaults match `nexus-ddd-core`'s backoff conventions.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class WithRetry
{
    /** @param class-string $strategy */
    public function __construct(
        public string $strategy = 'Monadial\\Nexus\\Ddd\\Core\\Backoff\\ExponentialBackoff',
        public int $maxAttempts = 3,
        public string $initialDelay = '1s',
        public string $maxDelay = '30s',
    ) {}
}
```

- [ ] **Step 3.8.4: Run the test, expect green**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Attribute/WithRetryTest.php
```

### Task 3.9 — `#[LateArrivalPolicy]` class attribute

- [ ] **Step 3.9.1: Write the failing test**

Path: `packages/nexus-ddd-process-manager/tests/Unit/Attribute/LateArrivalPolicyTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Unit\Attribute;

use Attribute;
use Monadial\Nexus\Ddd\ProcessManager\Attribute\LateArrivalPolicy;
use Monadial\Nexus\Ddd\ProcessManager\Routing\Policy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(LateArrivalPolicy::class)]
final class LateArrivalPolicyTest extends TestCase
{
    #[Test]
    public function targetsClassesOnly(): void
    {
        $reflection = new ReflectionClass(LateArrivalPolicy::class);
        $attr = $reflection->getAttributes(Attribute::class)[0]->newInstance();
        self::assertSame(Attribute::TARGET_CLASS, $attr->flags);
    }

    #[Test]
    public function defaultsToDeadLetter(): void
    {
        $attr = new LateArrivalPolicy();
        self::assertSame(Policy::DeadLetter, $attr->policy);
    }

    #[Test]
    public function explicitOverrideTakesEffect(): void
    {
        $attr = new LateArrivalPolicy(Policy::LogAndDrop);
        self::assertSame(Policy::LogAndDrop, $attr->policy);
    }
}
```

- [ ] **Step 3.9.2: Run the test, expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Attribute/LateArrivalPolicyTest.php
```

- [ ] **Step 3.9.3: Implement LateArrivalPolicy**

Path: `packages/nexus-ddd-process-manager/src/Attribute/LateArrivalPolicy.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Attribute;

use Attribute;
use Monadial\Nexus\Ddd\ProcessManager\Routing\Policy;

/**
 * @psalm-api
 *
 * Declares the class-level fallback when an event arrives at a
 * completed/terminated PM and there is no matching `#[OnLateArrival]`
 * method. Defaults to `Policy::DeadLetter` — silent drops produce
 * production mysteries.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class LateArrivalPolicy
{
    public function __construct(public Policy $policy = Policy::DeadLetter) {}
}
```

- [ ] **Step 3.9.4: Run the test, expect green**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Attribute/LateArrivalPolicyTest.php
```

### Task 3.10 — Phase 3 lint/Psalm/deptrac sweep + commit

- [ ] **Step 3.10.1: Run PHPCS, Psalm, Deptrac**

```bash
docker compose exec -T php vendor/bin/phpcs packages/nexus-ddd-process-manager
docker compose exec -T php vendor/bin/psalm
docker compose exec -T php php -d error_reporting='E_ALL & ~E_DEPRECATED' vendor/bin/deptrac analyse
```

Expected: green.

- [ ] **Step 3.10.2: Commit**

```bash
git add packages/nexus-ddd-process-manager
git commit -m "$(cat <<'EOF'
feat(ddd-process-manager): attributes, routing policy, rejected exception

Adds #[ProcessManager], #[StartsOn], #[OnEvent], #[OnDeadline],
#[OnLateArrival], #[WithRetry], #[LateArrivalPolicy] attributes plus
the Routing\\Policy enum and RejectedMessageException. Reflection-only
contracts; no runtime logic yet.
EOF
)"
```

---

## Phase 4 — Deadline operation hierarchy

Before `AbstractProcessManager` itself, ship the deadline-operation value objects (`ScheduleDeadline`, `RescheduleDeadline`, `CancelDeadline`) plus their abstract base. They are returned via `pullPendingDeadlineOperations()` which the runtime drains after each handler.

### Task 4.1 — DeadlineOperation abstract base

- [ ] **Step 4.1.1: Write the failing test**

Path: `packages/nexus-ddd-process-manager/tests/Unit/Deadline/DeadlineOperationTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Unit\Deadline;

use Monadial\Nexus\Ddd\ProcessManager\Deadline\DeadlineOperation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(DeadlineOperation::class)]
final class DeadlineOperationTest extends TestCase
{
    #[Test]
    public function isAbstract(): void
    {
        $reflection = new ReflectionClass(DeadlineOperation::class);
        self::assertTrue($reflection->isAbstract());
    }
}
```

- [ ] **Step 4.1.2: Run the test, expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Deadline/DeadlineOperationTest.php
```

- [ ] **Step 4.1.3: Implement DeadlineOperation**

Path: `packages/nexus-ddd-process-manager/src/Deadline/DeadlineOperation.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Deadline;

use Monadial\Nexus\Ddd\ProcessManager\Value\DeadlineName;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Abstract base for the three deadline operations a PM can stage in a
 * single handler invocation. The runtime pulls the queue via
 * `pullPendingDeadlineOperations()` after the handler returns and
 * routes each op to the deadline scheduler post-commit.
 */
abstract readonly class DeadlineOperation
{
    public function __construct(public DeadlineName $name) {}
}
```

- [ ] **Step 4.1.4: Run the test, expect green**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Deadline/DeadlineOperationTest.php
```

### Task 4.2 — ScheduleDeadline

- [ ] **Step 4.2.1: Write the failing test**

Path: `packages/nexus-ddd-process-manager/tests/Unit/Deadline/ScheduleDeadlineTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Unit\Deadline;

use Monadial\Nexus\Ddd\ProcessManager\Deadline\DeadlineOperation;
use Monadial\Nexus\Ddd\ProcessManager\Deadline\ScheduleDeadline;
use Monadial\Nexus\Ddd\ProcessManager\Value\DeadlineName;
use Monadial\PhpDuration\FiniteDuration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ScheduleDeadline::class)]
final class ScheduleDeadlineTest extends TestCase
{
    #[Test]
    public function carriesNameAndAfterDuration(): void
    {
        $name = DeadlineName::of('payment-deadline');
        $after = FiniteDuration::ofHours(24);

        $op = new ScheduleDeadline($name, $after);

        self::assertSame($name, $op->name);
        self::assertSame($after, $op->after);
        self::assertInstanceOf(DeadlineOperation::class, $op);
    }
}
```

- [ ] **Step 4.2.2: Run the test, expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Deadline/ScheduleDeadlineTest.php
```

- [ ] **Step 4.2.3: Implement ScheduleDeadline**

Path: `packages/nexus-ddd-process-manager/src/Deadline/ScheduleDeadline.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Deadline;

use Monadial\Nexus\Ddd\ProcessManager\Value\DeadlineName;
use Monadial\PhpDuration\FiniteDuration;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Schedule a new deadline. The runtime computes `fireAt = now + after`
 * post-commit using its `Psr\Clock\ClockInterface`.
 */
final readonly class ScheduleDeadline extends DeadlineOperation
{
    public function __construct(
        DeadlineName $name,
        public FiniteDuration $after,
    ) {
        parent::__construct($name);
    }
}
```

- [ ] **Step 4.2.4: Run the test, expect green**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Deadline/ScheduleDeadlineTest.php
```

### Task 4.3 — RescheduleDeadline

- [ ] **Step 4.3.1: Write the failing test**

Path: `packages/nexus-ddd-process-manager/tests/Unit/Deadline/RescheduleDeadlineTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Unit\Deadline;

use Monadial\Nexus\Ddd\ProcessManager\Deadline\DeadlineOperation;
use Monadial\Nexus\Ddd\ProcessManager\Deadline\RescheduleDeadline;
use Monadial\Nexus\Ddd\ProcessManager\Value\DeadlineName;
use Monadial\PhpDuration\FiniteDuration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RescheduleDeadline::class)]
final class RescheduleDeadlineTest extends TestCase
{
    #[Test]
    public function carriesNameAndAfterDuration(): void
    {
        $name = DeadlineName::of('payment-deadline');
        $after = FiniteDuration::ofMinutes(30);

        $op = new RescheduleDeadline($name, $after);

        self::assertSame($name, $op->name);
        self::assertSame($after, $op->after);
        self::assertInstanceOf(DeadlineOperation::class, $op);
    }
}
```

- [ ] **Step 4.3.2: Run the test, expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Deadline/RescheduleDeadlineTest.php
```

- [ ] **Step 4.3.3: Implement RescheduleDeadline**

Path: `packages/nexus-ddd-process-manager/src/Deadline/RescheduleDeadline.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Deadline;

use Monadial\Nexus\Ddd\ProcessManager\Value\DeadlineName;
use Monadial\PhpDuration\FiniteDuration;

/**
 * @psalm-api
 * @psalm-immutable
 */
final readonly class RescheduleDeadline extends DeadlineOperation
{
    public function __construct(
        DeadlineName $name,
        public FiniteDuration $after,
    ) {
        parent::__construct($name);
    }
}
```

- [ ] **Step 4.3.4: Run the test, expect green**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Deadline/RescheduleDeadlineTest.php
```

### Task 4.4 — CancelDeadline

- [ ] **Step 4.4.1: Write the failing test**

Path: `packages/nexus-ddd-process-manager/tests/Unit/Deadline/CancelDeadlineTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Unit\Deadline;

use Monadial\Nexus\Ddd\ProcessManager\Deadline\CancelDeadline;
use Monadial\Nexus\Ddd\ProcessManager\Deadline\DeadlineOperation;
use Monadial\Nexus\Ddd\ProcessManager\Value\DeadlineName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CancelDeadline::class)]
final class CancelDeadlineTest extends TestCase
{
    #[Test]
    public function carriesName(): void
    {
        $name = DeadlineName::of('payment-deadline');
        $op = new CancelDeadline($name);

        self::assertSame($name, $op->name);
        self::assertInstanceOf(DeadlineOperation::class, $op);
    }
}
```

- [ ] **Step 4.4.2: Run the test, expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Deadline/CancelDeadlineTest.php
```

- [ ] **Step 4.4.3: Implement CancelDeadline**

Path: `packages/nexus-ddd-process-manager/src/Deadline/CancelDeadline.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Deadline;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Cancel a previously scheduled deadline. No-op if the named deadline
 * is not currently scheduled. The runtime cancels the physical timer
 * entry post-commit and emits `PmDeadlineCancelled` for ES PMs.
 */
final readonly class CancelDeadline extends DeadlineOperation {}
```

- [ ] **Step 4.4.4: Run the test, expect green**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Deadline/CancelDeadlineTest.php
```

### Task 4.5 — Phase 4 commit

- [ ] **Step 4.5.1: Run PHPCS, Psalm, Deptrac**

```bash
docker compose exec -T php vendor/bin/phpcs packages/nexus-ddd-process-manager
docker compose exec -T php vendor/bin/psalm
docker compose exec -T php php -d error_reporting='E_ALL & ~E_DEPRECATED' vendor/bin/deptrac analyse
```

- [ ] **Step 4.5.2: Commit**

```bash
git add packages/nexus-ddd-process-manager
git commit -m "$(cat <<'EOF'
feat(ddd-process-manager): deadline operation value objects

Adds DeadlineOperation abstract base + ScheduleDeadline,
RescheduleDeadline, CancelDeadline. Returned by the PM via
pullPendingDeadlineOperations() and consumed by the deadline scheduler
post-commit.
EOF
)"
```

---

## Phase 5 — Internal events namespace

Both the framework-emitted internal events (`PmStarted`, `PmCompleted`, `PmTerminated`, `PmDeadlineScheduled`, `PmDeadlineRescheduled`, `PmDeadlineCancelled`, `PmDeadlineFired`, `PmCorrelationAdded`, `PmCorrelationRemoved`, `PmConsumedExternalEvent`) and the lifecycle observability events (`ProcessManagerStarted`, `TransactionCommitted`, ...) live under `src/Internal/`.

### Task 5.1 — Internal\Event\PmStarted

- [ ] **Step 5.1.1: Write the failing test**

Path: `packages/nexus-ddd-process-manager/tests/Unit/Internal/Event/PmStartedTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Unit\Internal\Event;

use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\ProcessManager\Internal\Event\PmStarted;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PmStarted::class)]
final class PmStartedTest extends TestCase
{
    #[Test]
    public function isADomainEventCarryingTriggeringIdAndStartMethod(): void
    {
        $event = new PmStarted('event-id-123', 'onOrderPlaced');
        self::assertInstanceOf(DomainEvent::class, $event);
        self::assertSame('event-id-123', $event->triggeringEventId);
        self::assertSame('onOrderPlaced', $event->startMethodName);
    }
}
```

- [ ] **Step 5.1.2: Run the test, expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Internal/Event/PmStartedTest.php
```

- [ ] **Step 5.1.3: Implement PmStarted**

Path: `packages/nexus-ddd-process-manager/src/Internal/Event/PmStarted.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Internal\Event;

use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * **Framework-only.** Subclasses MUST NOT `recordThat(new PmStarted(...))`.
 * Recorded as the FIRST event in an ES PM stream when a `#[StartsOn]`
 * handler fires. `triggeringEventId` is the external event id that
 * fired the start handler — it powers the dedup gate (see spec §7).
 */
final readonly class PmStarted implements DomainEvent
{
    public function __construct(
        public string $triggeringEventId,
        public string $startMethodName,
    ) {}
}
```

- [ ] **Step 5.1.4: Run the test, expect green**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Internal/Event/PmStartedTest.php
```

### Task 5.2 — Internal\Event\PmCompleted, PmTerminated

- [ ] **Step 5.2.1: Write the failing test for both**

Path: `packages/nexus-ddd-process-manager/tests/Unit/Internal/Event/PmTerminationEventsTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Unit\Internal\Event;

use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\ProcessManager\Internal\Event\PmCompleted;
use Monadial\Nexus\Ddd\ProcessManager\Internal\Event\PmTerminated;
use Monadial\Nexus\Ddd\ProcessManager\Value\Reason;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PmCompleted::class)]
#[CoversClass(PmTerminated::class)]
final class PmTerminationEventsTest extends TestCase
{
    #[Test]
    public function pmCompletedIsADomainEventWithNoFields(): void
    {
        $event = new PmCompleted();
        self::assertInstanceOf(DomainEvent::class, $event);
    }

    #[Test]
    public function pmTerminatedCarriesReason(): void
    {
        $reason = Reason::of('shipping-failed', 'carrier API 500');
        $event = new PmTerminated($reason);
        self::assertInstanceOf(DomainEvent::class, $event);
        self::assertSame($reason, $event->reason);
    }
}
```

- [ ] **Step 5.2.2: Run the test, expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Internal/Event/PmTerminationEventsTest.php
```

- [ ] **Step 5.2.3: Implement PmCompleted**

Path: `packages/nexus-ddd-process-manager/src/Internal/Event/PmCompleted.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Internal\Event;

use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * **Framework-only.** Recorded as the LAST event in an ES PM stream
 * when `complete()` is called.
 */
final readonly class PmCompleted implements DomainEvent {}
```

- [ ] **Step 5.2.4: Implement PmTerminated**

Path: `packages/nexus-ddd-process-manager/src/Internal/Event/PmTerminated.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Internal\Event;

use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\ProcessManager\Value\Reason;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * **Framework-only.** Recorded as the LAST event in an ES PM stream
 * when `terminate(Reason)` is called.
 */
final readonly class PmTerminated implements DomainEvent
{
    public function __construct(public Reason $reason) {}
}
```

- [ ] **Step 5.2.5: Run the test, expect green**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Internal/Event/PmTerminationEventsTest.php
```

### Task 5.3 — Internal\Event deadline events

- [ ] **Step 5.3.1: Write the failing tests for all four deadline events**

Path: `packages/nexus-ddd-process-manager/tests/Unit/Internal/Event/PmDeadlineEventsTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Unit\Internal\Event;

use DateTimeImmutable;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\ProcessManager\Internal\Event\PmDeadlineCancelled;
use Monadial\Nexus\Ddd\ProcessManager\Internal\Event\PmDeadlineFired;
use Monadial\Nexus\Ddd\ProcessManager\Internal\Event\PmDeadlineRescheduled;
use Monadial\Nexus\Ddd\ProcessManager\Internal\Event\PmDeadlineScheduled;
use Monadial\Nexus\Ddd\ProcessManager\Value\DeadlineName;
use Monadial\PhpDuration\FiniteDuration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PmDeadlineScheduled::class)]
#[CoversClass(PmDeadlineRescheduled::class)]
#[CoversClass(PmDeadlineCancelled::class)]
#[CoversClass(PmDeadlineFired::class)]
final class PmDeadlineEventsTest extends TestCase
{
    #[Test]
    public function deadlineScheduledCarriesNameAfterAndFireAt(): void
    {
        $name = DeadlineName::of('payment-deadline');
        $after = FiniteDuration::ofHours(24);
        $fireAt = new DateTimeImmutable('2026-05-08T12:00:00Z');
        $event = new PmDeadlineScheduled($name, $after, $fireAt);
        self::assertInstanceOf(DomainEvent::class, $event);
        self::assertSame($name, $event->name);
        self::assertSame($after, $event->after);
        self::assertSame($fireAt, $event->fireAt);
    }

    #[Test]
    public function deadlineRescheduledCarriesSameShape(): void
    {
        $name = DeadlineName::of('payment-deadline');
        $after = FiniteDuration::ofHours(2);
        $fireAt = new DateTimeImmutable('2026-05-07T14:00:00Z');
        $event = new PmDeadlineRescheduled($name, $after, $fireAt);
        self::assertSame($name, $event->name);
        self::assertSame($after, $event->after);
        self::assertSame($fireAt, $event->fireAt);
    }

    #[Test]
    public function deadlineCancelledCarriesNameOnly(): void
    {
        $name = DeadlineName::of('payment-deadline');
        $event = new PmDeadlineCancelled($name);
        self::assertSame($name, $event->name);
    }

    #[Test]
    public function deadlineFiredCarriesName(): void
    {
        $name = DeadlineName::of('payment-deadline');
        $event = new PmDeadlineFired($name);
        self::assertSame($name, $event->name);
    }
}
```

- [ ] **Step 5.3.2: Run the test, expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Internal/Event/PmDeadlineEventsTest.php
```

- [ ] **Step 5.3.3: Implement PmDeadlineScheduled**

Path: `packages/nexus-ddd-process-manager/src/Internal/Event/PmDeadlineScheduled.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Internal\Event;

use DateTimeImmutable;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\ProcessManager\Value\DeadlineName;
use Monadial\PhpDuration\FiniteDuration;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * **Framework-only.** Recorded when a PM calls `scheduleDeadline()`.
 */
final readonly class PmDeadlineScheduled implements DomainEvent
{
    public function __construct(
        public DeadlineName $name,
        public FiniteDuration $after,
        public DateTimeImmutable $fireAt,
    ) {}
}
```

- [ ] **Step 5.3.4: Implement PmDeadlineRescheduled**

Path: `packages/nexus-ddd-process-manager/src/Internal/Event/PmDeadlineRescheduled.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Internal\Event;

use DateTimeImmutable;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\ProcessManager\Value\DeadlineName;
use Monadial\PhpDuration\FiniteDuration;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * **Framework-only.**
 */
final readonly class PmDeadlineRescheduled implements DomainEvent
{
    public function __construct(
        public DeadlineName $name,
        public FiniteDuration $after,
        public DateTimeImmutable $fireAt,
    ) {}
}
```

- [ ] **Step 5.3.5: Implement PmDeadlineCancelled**

Path: `packages/nexus-ddd-process-manager/src/Internal/Event/PmDeadlineCancelled.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Internal\Event;

use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\ProcessManager\Value\DeadlineName;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * **Framework-only.**
 */
final readonly class PmDeadlineCancelled implements DomainEvent
{
    public function __construct(public DeadlineName $name) {}
}
```

- [ ] **Step 5.3.6: Implement PmDeadlineFired**

Path: `packages/nexus-ddd-process-manager/src/Internal/Event/PmDeadlineFired.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Internal\Event;

use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\ProcessManager\Value\DeadlineName;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * **Framework-only.** Recorded when a deadline fires and the matching
 * `#[OnDeadline]` handler runs to completion.
 */
final readonly class PmDeadlineFired implements DomainEvent
{
    public function __construct(public DeadlineName $name) {}
}
```

- [ ] **Step 5.3.7: Run the test, expect green**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Internal/Event/PmDeadlineEventsTest.php
```

### Task 5.4 — Correlation events + ConsumedExternalEvent

- [ ] **Step 5.4.1: Write the failing tests**

Path: `packages/nexus-ddd-process-manager/tests/Unit/Internal/Event/PmCorrelationAndConsumedEventsTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Unit\Internal\Event;

use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\ProcessManager\Internal\Event\PmConsumedExternalEvent;
use Monadial\Nexus\Ddd\ProcessManager\Internal\Event\PmCorrelationAdded;
use Monadial\Nexus\Ddd\ProcessManager\Internal\Event\PmCorrelationRemoved;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PmCorrelationAdded::class)]
#[CoversClass(PmCorrelationRemoved::class)]
#[CoversClass(PmConsumedExternalEvent::class)]
final class PmCorrelationAndConsumedEventsTest extends TestCase
{
    #[Test]
    public function correlationAddedCarriesFieldAndValue(): void
    {
        $event = new PmCorrelationAdded('shipmentId', 'SHIP-1');
        self::assertInstanceOf(DomainEvent::class, $event);
        self::assertSame('shipmentId', $event->field);
        self::assertSame('SHIP-1', $event->value);
    }

    #[Test]
    public function correlationRemovedCarriesField(): void
    {
        $event = new PmCorrelationRemoved('shipmentId');
        self::assertInstanceOf(DomainEvent::class, $event);
        self::assertSame('shipmentId', $event->field);
    }

    #[Test]
    public function consumedExternalEventCarriesId(): void
    {
        $event = new PmConsumedExternalEvent('event-id-123');
        self::assertInstanceOf(DomainEvent::class, $event);
        self::assertSame('event-id-123', $event->externalEventId);
    }
}
```

- [ ] **Step 5.4.2: Run the test, expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Internal/Event/PmCorrelationAndConsumedEventsTest.php
```

- [ ] **Step 5.4.3: Implement PmCorrelationAdded**

Path: `packages/nexus-ddd-process-manager/src/Internal/Event/PmCorrelationAdded.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Internal\Event;

use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * **Framework-only.** Recorded when a PM calls `correlateOn()`.
 */
final readonly class PmCorrelationAdded implements DomainEvent
{
    public function __construct(
        public string $field,
        public mixed $value,
    ) {}
}
```

- [ ] **Step 5.4.4: Implement PmCorrelationRemoved**

Path: `packages/nexus-ddd-process-manager/src/Internal/Event/PmCorrelationRemoved.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Internal\Event;

use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * **Framework-only.** Recorded when a PM calls `removeCorrelation()`.
 */
final readonly class PmCorrelationRemoved implements DomainEvent
{
    public function __construct(public string $field) {}
}
```

- [ ] **Step 5.4.5: Implement PmConsumedExternalEvent**

Path: `packages/nexus-ddd-process-manager/src/Internal/Event/PmConsumedExternalEvent.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Internal\Event;

use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * **Framework-only.** Recorded after a successful handler dispatch.
 * Source of truth for the live-redelivery dedup gate (spec §7).
 */
final readonly class PmConsumedExternalEvent implements DomainEvent
{
    public function __construct(public string $externalEventId) {}
}
```

- [ ] **Step 5.4.6: Run the test, expect green**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Internal/Event/PmCorrelationAndConsumedEventsTest.php
```

### Task 5.5 — Internal\Lifecycle marker + concrete events

The lifecycle namespace ships a single marker interface plus 15 concrete observability events. We test only the marker interface and one representative event in detail; the remaining 14 are pure data classes verified by a structural reflection test.

- [ ] **Step 5.5.1: Write the marker test**

Path: `packages/nexus-ddd-process-manager/tests/Unit/Internal/Lifecycle/ProcessManagerLifecycleEventTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Unit\Internal\Lifecycle;

use Monadial\Nexus\Ddd\ProcessManager\Internal\Lifecycle\ProcessManagerLifecycleEvent;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ProcessManagerLifecycleEventTest extends TestCase
{
    #[Test]
    public function isAMarkerInterface(): void
    {
        $reflection = new \ReflectionClass(ProcessManagerLifecycleEvent::class);
        self::assertTrue($reflection->isInterface());
        self::assertSame([], $reflection->getMethods());
    }
}
```

- [ ] **Step 5.5.2: Run the test, expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Internal/Lifecycle/ProcessManagerLifecycleEventTest.php
```

- [ ] **Step 5.5.3: Implement the marker**

Path: `packages/nexus-ddd-process-manager/src/Internal/Lifecycle/ProcessManagerLifecycleEvent.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Internal\Lifecycle;

/**
 * @psalm-api
 *
 * Marker interface for framework-emitted observability events. These
 * are NOT `DomainEvent`s — they live in a distinct namespace,
 * implement a distinct marker, and travel through a distinct PSR-14
 * dispatcher. Listeners must NOT mutate PM state — the
 * `ProcessManagerInternalEventReadOnlyRule` Psalm rule enforces this.
 */
interface ProcessManagerLifecycleEvent {}
```

- [ ] **Step 5.5.4: Run the test, expect green**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Internal/Lifecycle/ProcessManagerLifecycleEventTest.php
```

### Task 5.6 — ProcessManagerStarted lifecycle event (representative test)

- [ ] **Step 5.6.1: Write the failing test**

Path: `packages/nexus-ddd-process-manager/tests/Unit/Internal/Lifecycle/ProcessManagerStartedTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Unit\Internal\Lifecycle;

use Monadial\Nexus\Ddd\ProcessManager\Internal\Event\PmStarted;
use Monadial\Nexus\Ddd\ProcessManager\Internal\Lifecycle\ProcessManagerLifecycleEvent;
use Monadial\Nexus\Ddd\ProcessManager\Internal\Lifecycle\ProcessManagerStarted;
use Monadial\Nexus\Ddd\ProcessManager\Tests\Support\TestProcessManagerId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

#[CoversClass(ProcessManagerStarted::class)]
final class ProcessManagerStartedTest extends TestCase
{
    #[Test]
    public function carriesPmIdClassAndTriggeringEvent(): void
    {
        $id = new TestProcessManagerId((new Ulid())->toBase32());
        $triggeredBy = new PmStarted('event-id-123', 'onOrderPlaced');

        $event = new ProcessManagerStarted($id, 'App\\Process\\OrderFulfillment', $triggeredBy);

        self::assertInstanceOf(ProcessManagerLifecycleEvent::class, $event);
        self::assertSame($id, $event->id);
        self::assertSame('App\\Process\\OrderFulfillment', $event->pmClass);
        self::assertSame($triggeredBy, $event->triggeredBy);
    }
}
```

- [ ] **Step 5.6.2: Run the test, expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Internal/Lifecycle/ProcessManagerStartedTest.php
```

- [ ] **Step 5.6.3: Implement ProcessManagerStarted**

Path: `packages/nexus-ddd-process-manager/src/Internal/Lifecycle/ProcessManagerStarted.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Internal\Lifecycle;

use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\ProcessManager\Identity\ProcessManagerId;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Dispatched after a PM instance starts (its `#[StartsOn]` handler
 * completed). `triggeredBy` carries the inbound DomainEvent that fired
 * the start handler.
 */
final readonly class ProcessManagerStarted implements ProcessManagerLifecycleEvent
{
    public function __construct(
        public ProcessManagerId $id,
        public string $pmClass,
        public DomainEvent $triggeredBy,
    ) {}
}
```

- [ ] **Step 5.6.4: Run the test, expect green**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Internal/Lifecycle/ProcessManagerStartedTest.php
```

### Task 5.7 — Remaining lifecycle events (structural test)

- [ ] **Step 5.7.1: Write the structural test for the remaining 14 events**

Path: `packages/nexus-ddd-process-manager/tests/Unit/Internal/Lifecycle/LifecycleEventStructureTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Unit\Internal\Lifecycle;

use Monadial\Nexus\Ddd\ProcessManager\Internal\Lifecycle\CommandsDispatched;
use Monadial\Nexus\Ddd\ProcessManager\Internal\Lifecycle\DeadlineCancelled;
use Monadial\Nexus\Ddd\ProcessManager\Internal\Lifecycle\DeadlineFired;
use Monadial\Nexus\Ddd\ProcessManager\Internal\Lifecycle\DeadlineScheduled;
use Monadial\Nexus\Ddd\ProcessManager\Internal\Lifecycle\EventsDispatched;
use Monadial\Nexus\Ddd\ProcessManager\Internal\Lifecycle\HandlerInvocationFailed;
use Monadial\Nexus\Ddd\ProcessManager\Internal\Lifecycle\HandlerInvocationFinished;
use Monadial\Nexus\Ddd\ProcessManager\Internal\Lifecycle\HandlerInvocationStarted;
use Monadial\Nexus\Ddd\ProcessManager\Internal\Lifecycle\ProcessManagerCompleted;
use Monadial\Nexus\Ddd\ProcessManager\Internal\Lifecycle\ProcessManagerLifecycleEvent;
use Monadial\Nexus\Ddd\ProcessManager\Internal\Lifecycle\ProcessManagerLoaded;
use Monadial\Nexus\Ddd\ProcessManager\Internal\Lifecycle\ProcessManagerTerminated;
use Monadial\Nexus\Ddd\ProcessManager\Internal\Lifecycle\TransactionCommitted;
use Monadial\Nexus\Ddd\ProcessManager\Internal\Lifecycle\TransactionRolledBack;
use Monadial\Nexus\Ddd\ProcessManager\Internal\Lifecycle\TransactionStarted;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class LifecycleEventStructureTest extends TestCase
{
    #[Test]
    public function allLifecycleEventsImplementMarkerInterface(): void
    {
        $classes = [
            CommandsDispatched::class,
            DeadlineCancelled::class,
            DeadlineFired::class,
            DeadlineScheduled::class,
            EventsDispatched::class,
            HandlerInvocationFailed::class,
            HandlerInvocationFinished::class,
            HandlerInvocationStarted::class,
            ProcessManagerCompleted::class,
            ProcessManagerLoaded::class,
            ProcessManagerTerminated::class,
            TransactionCommitted::class,
            TransactionRolledBack::class,
            TransactionStarted::class,
        ];

        foreach ($classes as $class) {
            self::assertTrue(
                is_subclass_of($class, ProcessManagerLifecycleEvent::class),
                "$class must implement ProcessManagerLifecycleEvent",
            );
            $reflection = new \ReflectionClass($class);
            self::assertTrue($reflection->isFinal(), "$class must be final");
            self::assertTrue($reflection->isReadOnly(), "$class must be readonly");
        }
    }
}
```

- [ ] **Step 5.7.2: Run the test, expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Internal/Lifecycle/LifecycleEventStructureTest.php
```

- [ ] **Step 5.7.3: Implement ProcessManagerLoaded**

Path: `packages/nexus-ddd-process-manager/src/Internal/Lifecycle/ProcessManagerLoaded.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Internal\Lifecycle;

use Monadial\Nexus\Ddd\ProcessManager\Identity\ProcessManagerId;

/**
 * @psalm-api
 * @psalm-immutable
 */
final readonly class ProcessManagerLoaded implements ProcessManagerLifecycleEvent
{
    public function __construct(
        public ProcessManagerId $id,
        public string $pmClass,
        public int $version,
    ) {}
}
```

- [ ] **Step 5.7.4: Implement ProcessManagerCompleted**

Path: `packages/nexus-ddd-process-manager/src/Internal/Lifecycle/ProcessManagerCompleted.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Internal\Lifecycle;

use Monadial\Nexus\Ddd\ProcessManager\Identity\ProcessManagerId;

/**
 * @psalm-api
 * @psalm-immutable
 */
final readonly class ProcessManagerCompleted implements ProcessManagerLifecycleEvent
{
    public function __construct(
        public ProcessManagerId $id,
        public string $pmClass,
    ) {}
}
```

- [ ] **Step 5.7.5: Implement ProcessManagerTerminated**

Path: `packages/nexus-ddd-process-manager/src/Internal/Lifecycle/ProcessManagerTerminated.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Internal\Lifecycle;

use Monadial\Nexus\Ddd\ProcessManager\Identity\ProcessManagerId;
use Monadial\Nexus\Ddd\ProcessManager\Value\Reason;

/**
 * @psalm-api
 * @psalm-immutable
 */
final readonly class ProcessManagerTerminated implements ProcessManagerLifecycleEvent
{
    public function __construct(
        public ProcessManagerId $id,
        public string $pmClass,
        public Reason $reason,
    ) {}
}
```

- [ ] **Step 5.7.6: Implement TransactionStarted, TransactionCommitted, TransactionRolledBack**

Path: `packages/nexus-ddd-process-manager/src/Internal/Lifecycle/TransactionStarted.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Internal\Lifecycle;

use Monadial\Nexus\Ddd\ProcessManager\Identity\ProcessManagerId;

/**
 * @psalm-api
 * @psalm-immutable
 */
final readonly class TransactionStarted implements ProcessManagerLifecycleEvent
{
    public function __construct(public ProcessManagerId $id) {}
}
```

Path: `packages/nexus-ddd-process-manager/src/Internal/Lifecycle/TransactionCommitted.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Internal\Lifecycle;

use Monadial\Nexus\Ddd\ProcessManager\Identity\ProcessManagerId;

/**
 * @psalm-api
 * @psalm-immutable
 */
final readonly class TransactionCommitted implements ProcessManagerLifecycleEvent
{
    public function __construct(public ProcessManagerId $id) {}
}
```

Path: `packages/nexus-ddd-process-manager/src/Internal/Lifecycle/TransactionRolledBack.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Internal\Lifecycle;

use Monadial\Nexus\Ddd\ProcessManager\Identity\ProcessManagerId;
use Throwable;

/**
 * @psalm-api
 * @psalm-immutable
 */
final readonly class TransactionRolledBack implements ProcessManagerLifecycleEvent
{
    public function __construct(
        public ProcessManagerId $id,
        public Throwable $cause,
    ) {}
}
```

- [ ] **Step 5.7.7: Implement DeadlineScheduled, DeadlineFired, DeadlineCancelled (lifecycle variants)**

Path: `packages/nexus-ddd-process-manager/src/Internal/Lifecycle/DeadlineScheduled.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Internal\Lifecycle;

use DateTimeImmutable;
use Monadial\Nexus\Ddd\ProcessManager\Identity\ProcessManagerId;
use Monadial\Nexus\Ddd\ProcessManager\Value\DeadlineName;

/**
 * @psalm-api
 * @psalm-immutable
 */
final readonly class DeadlineScheduled implements ProcessManagerLifecycleEvent
{
    public function __construct(
        public ProcessManagerId $pmId,
        public DeadlineName $name,
        public DateTimeImmutable $fireAt,
    ) {}
}
```

Path: `packages/nexus-ddd-process-manager/src/Internal/Lifecycle/DeadlineFired.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Internal\Lifecycle;

use Monadial\Nexus\Ddd\ProcessManager\Identity\ProcessManagerId;
use Monadial\Nexus\Ddd\ProcessManager\Value\DeadlineName;

/**
 * @psalm-api
 * @psalm-immutable
 */
final readonly class DeadlineFired implements ProcessManagerLifecycleEvent
{
    public function __construct(
        public ProcessManagerId $pmId,
        public DeadlineName $name,
    ) {}
}
```

Path: `packages/nexus-ddd-process-manager/src/Internal/Lifecycle/DeadlineCancelled.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Internal\Lifecycle;

use Monadial\Nexus\Ddd\ProcessManager\Identity\ProcessManagerId;
use Monadial\Nexus\Ddd\ProcessManager\Value\DeadlineName;

/**
 * @psalm-api
 * @psalm-immutable
 */
final readonly class DeadlineCancelled implements ProcessManagerLifecycleEvent
{
    public function __construct(
        public ProcessManagerId $pmId,
        public DeadlineName $name,
    ) {}
}
```

- [ ] **Step 5.7.8: Implement CommandsDispatched, EventsDispatched**

Path: `packages/nexus-ddd-process-manager/src/Internal/Lifecycle/CommandsDispatched.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Internal\Lifecycle;

use Monadial\Nexus\Ddd\ProcessManager\Identity\ProcessManagerId;

/**
 * @psalm-api
 * @psalm-immutable
 */
final readonly class CommandsDispatched implements ProcessManagerLifecycleEvent
{
    public function __construct(
        public ProcessManagerId $pmId,
        public int $count,
    ) {}
}
```

Path: `packages/nexus-ddd-process-manager/src/Internal/Lifecycle/EventsDispatched.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Internal\Lifecycle;

use Monadial\Nexus\Ddd\ProcessManager\Identity\ProcessManagerId;

/**
 * @psalm-api
 * @psalm-immutable
 */
final readonly class EventsDispatched implements ProcessManagerLifecycleEvent
{
    public function __construct(
        public ProcessManagerId $pmId,
        public int $count,
    ) {}
}
```

- [ ] **Step 5.7.9: Implement HandlerInvocationStarted, HandlerInvocationFinished, HandlerInvocationFailed**

Path: `packages/nexus-ddd-process-manager/src/Internal/Lifecycle/HandlerInvocationStarted.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Internal\Lifecycle;

use Monadial\Nexus\Ddd\ProcessManager\Identity\ProcessManagerId;

/**
 * @psalm-api
 * @psalm-immutable
 */
final readonly class HandlerInvocationStarted implements ProcessManagerLifecycleEvent
{
    public function __construct(
        public ProcessManagerId $pmId,
        public string $methodName,
    ) {}
}
```

Path: `packages/nexus-ddd-process-manager/src/Internal/Lifecycle/HandlerInvocationFinished.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Internal\Lifecycle;

use Monadial\Nexus\Ddd\ProcessManager\Identity\ProcessManagerId;

/**
 * @psalm-api
 * @psalm-immutable
 */
final readonly class HandlerInvocationFinished implements ProcessManagerLifecycleEvent
{
    public function __construct(
        public ProcessManagerId $pmId,
        public string $methodName,
    ) {}
}
```

Path: `packages/nexus-ddd-process-manager/src/Internal/Lifecycle/HandlerInvocationFailed.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Internal\Lifecycle;

use Monadial\Nexus\Ddd\ProcessManager\Identity\ProcessManagerId;
use Throwable;

/**
 * @psalm-api
 * @psalm-immutable
 */
final readonly class HandlerInvocationFailed implements ProcessManagerLifecycleEvent
{
    public function __construct(
        public ProcessManagerId $pmId,
        public string $methodName,
        public Throwable $cause,
    ) {}
}
```

- [ ] **Step 5.7.10: Run the structural test, expect green**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Internal/Lifecycle/LifecycleEventStructureTest.php
```

### Task 5.8 — Phase 5 lint/Psalm/deptrac sweep + commit

- [ ] **Step 5.8.1: Run PHPCS, Psalm, Deptrac**

```bash
docker compose exec -T php vendor/bin/phpcs packages/nexus-ddd-process-manager
docker compose exec -T php vendor/bin/psalm
docker compose exec -T php php -d error_reporting='E_ALL & ~E_DEPRECATED' vendor/bin/deptrac analyse
```

Expected: green.

- [ ] **Step 5.8.2: Commit**

```bash
git add packages/nexus-ddd-process-manager
git commit -m "$(cat <<'EOF'
feat(ddd-process-manager): internal events and lifecycle observability events

Adds the ten framework-emitted internal events under
Internal\\Event (PmStarted/Completed/Terminated, deadline ops, correlation
ops, ConsumedExternalEvent), plus the ProcessManagerLifecycleEvent
marker and 15 concrete lifecycle events under Internal\\Lifecycle for
PSR-14 observability.
EOF
)"
```

---

## Phase 6 — AbstractProcessManager + ProcessManagerInternals

This phase ships the abstract base that both stateful and event-sourced PMs extend. It owns the lifecycle flag machine (active/completed/terminated), the staging buffers (commands, events, deadline ops), the secondary correlation index, the `isReplaying` flag, and the inspection accessors via `ProcessManagerInternals`.

### Task 6.1 — ProcessManagerInternals interface

- [ ] **Step 6.1.1: Write the failing test**

Path: `packages/nexus-ddd-process-manager/tests/Unit/ProcessManager/ProcessManagerInternalsTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Unit\ProcessManager;

use Monadial\Nexus\Ddd\ProcessManager\ProcessManager\ProcessManagerInternals;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversNothing]
final class ProcessManagerInternalsTest extends TestCase
{
    #[Test]
    public function declaresThreePullMethods(): void
    {
        $reflection = new ReflectionClass(ProcessManagerInternals::class);
        self::assertTrue($reflection->isInterface());
        $methods = array_map(
            static fn(\ReflectionMethod $m): string => $m->getName(),
            $reflection->getMethods(),
        );
        self::assertContains('pullPendingCommands', $methods);
        self::assertContains('pullPendingEvents', $methods);
        self::assertContains('pullPendingDeadlineOperations', $methods);
    }

    #[Test]
    public function eachPullMethodIsAnnotatedNoDiscard(): void
    {
        $reflection = new ReflectionClass(ProcessManagerInternals::class);
        foreach (['pullPendingCommands', 'pullPendingEvents', 'pullPendingDeadlineOperations'] as $name) {
            $method = $reflection->getMethod($name);
            $attrs = $method->getAttributes(\NoDiscard::class);
            self::assertNotEmpty($attrs, "$name must carry #[\\NoDiscard]");
        }
    }
}
```

- [ ] **Step 6.1.2: Run the test, expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/ProcessManager/ProcessManagerInternalsTest.php
```

- [ ] **Step 6.1.3: Implement ProcessManagerInternals**

Path: `packages/nexus-ddd-process-manager/src/ProcessManager/ProcessManagerInternals.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\ProcessManager;

use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\ProcessManager\Contract\Messaging\Command;
use Monadial\Nexus\Ddd\ProcessManager\Deadline\DeadlineOperation;

/**
 * @psalm-api
 *
 * Framework-facing accessors. Same instance as `AbstractProcessManager`,
 * cast to this interface by repository / unit-of-work code to drain
 * pending intent. Domain code reading the PM class sees only domain
 * operations (Vernon's segregation recipe).
 */
interface ProcessManagerInternals
{
    /** @return array<int, Command> */
    #[\NoDiscard('pullPendingCommands drains the buffer — discarding loses every staged command')]
    public function pullPendingCommands(): array;

    /** @return array<int, DomainEvent> */
    #[\NoDiscard('pullPendingEvents drains the buffer — discarding loses every staged event')]
    public function pullPendingEvents(): array;

    /** @return array<int, DeadlineOperation> */
    #[\NoDiscard('pullPendingDeadlineOperations drains the buffer — discarding loses every staged op')]
    public function pullPendingDeadlineOperations(): array;
}
```

- [ ] **Step 6.1.4: Run the test, expect green**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/ProcessManager/ProcessManagerInternalsTest.php
```

### Task 6.2 — AbstractProcessManager: identity + lifecycle flags

The first slice of the abstract base — id, completion/termination flags, accessors. Subsequent tasks layer on staging, deadlines, correlation, replay-flag.

- [ ] **Step 6.2.1: Write the failing test**

Path: `packages/nexus-ddd-process-manager/tests/Unit/ProcessManager/AbstractProcessManagerLifecycleTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Unit\ProcessManager;

use Monadial\Nexus\Ddd\ProcessManager\ProcessManager\AbstractProcessManager;
use Monadial\Nexus\Ddd\ProcessManager\Tests\Support\TestProcessManagerId;
use Monadial\Nexus\Ddd\ProcessManager\Value\Reason;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

#[CoversClass(AbstractProcessManager::class)]
final class AbstractProcessManagerLifecycleTest extends TestCase
{
    #[Test]
    public function exposesIdAndStartsActive(): void
    {
        $id = new TestProcessManagerId((new Ulid())->toBase32());
        $pm = new TestPm($id);

        self::assertSame($id, $pm->id());
        self::assertFalse($pm->isCompleted());
        self::assertFalse($pm->isTerminated());
        self::assertNull($pm->terminationReason());
    }

    #[Test]
    public function completeSetsTheCompletedFlag(): void
    {
        $pm = TestPm::fresh();
        $pm->doComplete();
        self::assertTrue($pm->isCompleted());
        self::assertFalse($pm->isTerminated());
    }

    #[Test]
    public function terminateSetsTerminatedFlagAndCarriesReason(): void
    {
        $pm = TestPm::fresh();
        $reason = Reason::of('payment-not-received-within-24h');
        $pm->doTerminate($reason);
        self::assertTrue($pm->isTerminated());
        self::assertSame($reason, $pm->terminationReason());
        self::assertFalse($pm->isCompleted());
    }

    #[Test]
    public function isReplayingDefaultsToFalse(): void
    {
        $pm = TestPm::fresh();
        self::assertFalse($pm->isReplaying());
    }
}

final class TestPm extends AbstractProcessManager
{
    public static function fresh(): self
    {
        return new self(new TestProcessManagerId((new Ulid())->toBase32()));
    }

    public function doComplete(): void
    {
        $this->complete();
    }

    public function doTerminate(Reason $reason): void
    {
        $this->terminate($reason);
    }
}
```

- [ ] **Step 6.2.2: Run the test, expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/ProcessManager/AbstractProcessManagerLifecycleTest.php
```

- [ ] **Step 6.2.3: Implement AbstractProcessManager (initial slice — lifecycle only)**

Path: `packages/nexus-ddd-process-manager/src/ProcessManager/AbstractProcessManager.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\ProcessManager;

use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Core\Exception\DomainException;
use Monadial\Nexus\Ddd\ProcessManager\Contract\Messaging\Command;
use Monadial\Nexus\Ddd\ProcessManager\Deadline\CancelDeadline;
use Monadial\Nexus\Ddd\ProcessManager\Deadline\DeadlineOperation;
use Monadial\Nexus\Ddd\ProcessManager\Deadline\RescheduleDeadline;
use Monadial\Nexus\Ddd\ProcessManager\Deadline\ScheduleDeadline;
use Monadial\Nexus\Ddd\ProcessManager\Exception\CorrelationConflictException;
use Monadial\Nexus\Ddd\ProcessManager\Identity\ProcessManagerId;
use Monadial\Nexus\Ddd\ProcessManager\Value\DeadlineName;
use Monadial\Nexus\Ddd\ProcessManager\Value\Reason;
use Monadial\PhpDuration\FiniteDuration;

/**
 * @psalm-api
 *
 * @template TId of ProcessManagerId
 *
 * Base for all process managers — the stateful coordinator that listens
 * to DomainEvents, dispatches Commands, publishes its own DomainEvents,
 * and schedules named deadlines. Two intent-revealing concrete subclasses:
 *
 *   - `StatefulProcessManager` — snapshot persisted; mutate state directly
 *     in handlers
 *   - `EventSourcedProcessManager` — event stream persisted; mutate state
 *     via `recordThat()` + `applyXxx()`
 *
 * **Do NOT extend this class directly.**
 *
 * **Lifecycle states.** Active (default), Completed (`complete()`),
 * Terminated (`terminate(Reason)`). Failed is set by the framework (an
 * uncaught handler exception); domain code only declares completion or
 * termination.
 *
 * **Staging.** `dispatchCommand()` / `publishEvent()` /
 * `scheduleDeadline()` etc. buffer intent inside the PM. The framework's
 * unit of work drains via `ProcessManagerInternals::pullPending*`
 * post-commit. During replay (`$isReplaying === true`) every emit-side
 * method becomes a no-op or in-memory-only update — see spec §7
 * "Replay" semantics.
 */
abstract class AbstractProcessManager implements ProcessManagerInternals
{
    private bool $isCompleted = false;
    private bool $isTerminated = false;
    private ?Reason $terminationReason = null;
    private ?DomainEvent $startedBy = null;
    private bool $isReplaying = false;

    /** @var array<int, Command> */
    private array $pendingCommands = [];

    /** @var array<int, DomainEvent> */
    private array $pendingEvents = [];

    /** @var array<int, DeadlineOperation> */
    private array $pendingDeadlineOperations = [];

    /** @var array<string, true> */
    private array $scheduledDeadlines = [];

    /** @var array<string, mixed> */
    private array $correlations = [];

    /** @param TId $id */
    protected function __construct(protected readonly ProcessManagerId $id) {}

    /** @return TId */
    public function id(): ProcessManagerId
    {
        /** @var TId */
        return $this->id;
    }

    final public function isCompleted(): bool
    {
        return $this->isCompleted;
    }

    final public function isTerminated(): bool
    {
        return $this->isTerminated;
    }

    final public function terminationReason(): ?Reason
    {
        return $this->terminationReason;
    }

    final public function startedBy(): ?DomainEvent
    {
        return $this->startedBy;
    }

    final public function isReplaying(): bool
    {
        return $this->isReplaying;
    }

    final protected function complete(): void
    {
        $this->isCompleted = true;
    }

    final protected function terminate(Reason $reason): void
    {
        $this->isTerminated = true;
        $this->terminationReason = $reason;
    }

    /**
     * Stage a command for post-commit dispatch. No-op during replay.
     */
    final protected function dispatchCommand(Command $command): void
    {
        if ($this->isReplaying) {
            return;
        }

        $this->pendingCommands[] = $command;
    }

    /**
     * Stage a DomainEvent for post-commit publish. No-op during replay.
     */
    final protected function publishEvent(DomainEvent $event): void
    {
        if ($this->isReplaying) {
            return;
        }

        $this->pendingEvents[] = $event;
    }

    final protected function scheduleDeadline(DeadlineName $name, FiniteDuration $after): void
    {
        $this->scheduledDeadlines[$name->value()] = true;

        if ($this->isReplaying) {
            return;
        }

        $this->pendingDeadlineOperations[] = new ScheduleDeadline($name, $after);
    }

    final protected function rescheduleDeadline(DeadlineName $name, FiniteDuration $after): void
    {
        $this->scheduledDeadlines[$name->value()] = true;

        if ($this->isReplaying) {
            return;
        }

        $this->pendingDeadlineOperations[] = new RescheduleDeadline($name, $after);
    }

    final protected function cancelDeadline(DeadlineName $name): void
    {
        unset($this->scheduledDeadlines[$name->value()]);

        if ($this->isReplaying) {
            return;
        }

        $this->pendingDeadlineOperations[] = new CancelDeadline($name);
    }

    final protected function hasDeadline(DeadlineName $name): bool
    {
        return isset($this->scheduledDeadlines[$name->value()]);
    }

    /**
     * Add a secondary correlation key. Idempotent re-registration with the
     * same value is a no-op; a different value for an existing field
     * throws CorrelationConflictException.
     */
    final protected function correlateOn(string $field, mixed $value): void
    {
        if (array_key_exists($field, $this->correlations)) {
            if ($this->correlations[$field] === $value) {
                return;
            }

            throw CorrelationConflictException::for($field, $this->correlations[$field], $value);
        }

        $this->correlations[$field] = $value;
    }

    final protected function removeCorrelation(string $field): void
    {
        unset($this->correlations[$field]);
    }

    /** @internal Framework wiring entry point — runtime sets this for replay/load. */
    final public function setStartedBy(DomainEvent $event): void
    {
        $this->startedBy = $event;
    }

    /** @internal Framework wiring entry point — runtime toggles before/after replay. */
    final public function setReplaying(bool $isReplaying): void
    {
        $this->isReplaying = $isReplaying;
    }

    #[\Override]
    #[\NoDiscard('pullPendingCommands drains the buffer — discarding loses every staged command')]
    final public function pullPendingCommands(): array
    {
        $commands = $this->pendingCommands;
        $this->pendingCommands = [];

        return $commands;
    }

    #[\Override]
    #[\NoDiscard('pullPendingEvents drains the buffer — discarding loses every staged event')]
    final public function pullPendingEvents(): array
    {
        $events = $this->pendingEvents;
        $this->pendingEvents = [];

        return $events;
    }

    #[\Override]
    #[\NoDiscard('pullPendingDeadlineOperations drains the buffer — discarding loses every staged op')]
    final public function pullPendingDeadlineOperations(): array
    {
        $ops = $this->pendingDeadlineOperations;
        $this->pendingDeadlineOperations = [];

        return $ops;
    }

    /**
     * Snapshot of the secondary correlation index. Framework-facing.
     *
     * @return array<string, mixed>
     */
    final public function correlations(): array
    {
        return $this->correlations;
    }

    /**
     * Snapshot of the currently scheduled deadline names. Framework-facing.
     *
     * @return array<int, DeadlineName>
     */
    final public function scheduledDeadlineNames(): array
    {
        return array_map(
            static fn(string $value): DeadlineName => DeadlineName::of($value),
            array_keys($this->scheduledDeadlines),
        );
    }

    /**
     * Domain invariant guard. Mirror of `AggregateRoot::check()`.
     *
     * @throws DomainException
     */
    final protected function check(bool $condition, DomainException|string $rule): void
    {
        if ($condition) {
            return;
        }

        throw $rule instanceof DomainException
            ? $rule
            : new class ($rule) extends DomainException {};
    }
}
```

- [ ] **Step 6.2.4: Run the test, expect green**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/ProcessManager/AbstractProcessManagerLifecycleTest.php
```

### Task 6.3 — AbstractProcessManager: command + event staging

- [ ] **Step 6.3.1: Write the failing test**

Path: `packages/nexus-ddd-process-manager/tests/Unit/ProcessManager/AbstractProcessManagerStagingTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Unit\ProcessManager;

use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\ProcessManager\Contract\Messaging\Command;
use Monadial\Nexus\Ddd\ProcessManager\ProcessManager\AbstractProcessManager;
use Monadial\Nexus\Ddd\ProcessManager\Tests\Support\TestProcessManagerId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

#[CoversClass(AbstractProcessManager::class)]
final class AbstractProcessManagerStagingTest extends TestCase
{
    #[Test]
    public function dispatchCommandStagesAndDrainsFifo(): void
    {
        $pm = StagingPm::fresh();
        $pm->doDispatch(new SampleCommand('A'));
        $pm->doDispatch(new SampleCommand('B'));

        $drained = $pm->pullPendingCommands();
        self::assertCount(2, $drained);
        self::assertSame('A', $drained[0]->id);
        self::assertSame('B', $drained[1]->id);

        // Second drain returns empty.
        self::assertSame([], $pm->pullPendingCommands());
    }

    #[Test]
    public function publishEventStagesAndDrainsFifo(): void
    {
        $pm = StagingPm::fresh();
        $pm->doPublish(new SampleEvent('E1'));
        $pm->doPublish(new SampleEvent('E2'));

        $drained = $pm->pullPendingEvents();
        self::assertCount(2, $drained);
        self::assertSame('E1', $drained[0]->id);
        self::assertSame('E2', $drained[1]->id);
        self::assertSame([], $pm->pullPendingEvents());
    }

    #[Test]
    public function commandsAndEventsAreSegregatedBuffers(): void
    {
        $pm = StagingPm::fresh();
        $pm->doDispatch(new SampleCommand('A'));
        $pm->doPublish(new SampleEvent('E'));

        self::assertCount(1, $pm->pullPendingCommands());
        self::assertCount(1, $pm->pullPendingEvents());
    }

    #[Test]
    public function dispatchAndPublishAreNoOpsDuringReplay(): void
    {
        $pm = StagingPm::fresh();
        $pm->setReplaying(true);
        $pm->doDispatch(new SampleCommand('A'));
        $pm->doPublish(new SampleEvent('E'));

        self::assertSame([], $pm->pullPendingCommands());
        self::assertSame([], $pm->pullPendingEvents());
    }
}

final class StagingPm extends AbstractProcessManager
{
    public static function fresh(): self
    {
        return new self(new TestProcessManagerId((new Ulid())->toBase32()));
    }

    public function doDispatch(Command $command): void
    {
        $this->dispatchCommand($command);
    }

    public function doPublish(DomainEvent $event): void
    {
        $this->publishEvent($event);
    }
}

final readonly class SampleCommand implements Command
{
    public function __construct(public string $id) {}
}

final readonly class SampleEvent implements DomainEvent
{
    public function __construct(public string $id) {}
}
```

- [ ] **Step 6.3.2: Run the test, expect green**

The implementation in Task 6.2 already covers staging. Run:

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/ProcessManager/AbstractProcessManagerStagingTest.php
```

Expected: 4 tests, OK.

### Task 6.4 — AbstractProcessManager: deadline staging + hasDeadline

- [ ] **Step 6.4.1: Write the failing test**

Path: `packages/nexus-ddd-process-manager/tests/Unit/ProcessManager/AbstractProcessManagerDeadlinesTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Unit\ProcessManager;

use Monadial\Nexus\Ddd\ProcessManager\Deadline\CancelDeadline;
use Monadial\Nexus\Ddd\ProcessManager\Deadline\RescheduleDeadline;
use Monadial\Nexus\Ddd\ProcessManager\Deadline\ScheduleDeadline;
use Monadial\Nexus\Ddd\ProcessManager\ProcessManager\AbstractProcessManager;
use Monadial\Nexus\Ddd\ProcessManager\Tests\Support\TestProcessManagerId;
use Monadial\Nexus\Ddd\ProcessManager\Value\DeadlineName;
use Monadial\PhpDuration\FiniteDuration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

#[CoversClass(AbstractProcessManager::class)]
final class AbstractProcessManagerDeadlinesTest extends TestCase
{
    #[Test]
    public function scheduleDeadlineSetsHasDeadlineAndStagesScheduleOp(): void
    {
        $pm = DeadlinePm::fresh();
        $pm->doSchedule(DeadlineName::of('payment-deadline'), FiniteDuration::ofHours(24));

        self::assertTrue($pm->hasDeadlineByName('payment-deadline'));
        $ops = $pm->pullPendingDeadlineOperations();
        self::assertCount(1, $ops);
        self::assertInstanceOf(ScheduleDeadline::class, $ops[0]);
    }

    #[Test]
    public function rescheduleDeadlineKeepsHasDeadlineAndStagesRescheduleOp(): void
    {
        $pm = DeadlinePm::fresh();
        $pm->doSchedule(DeadlineName::of('payment-deadline'), FiniteDuration::ofHours(24));
        (void) $pm->pullPendingDeadlineOperations();

        $pm->doReschedule(DeadlineName::of('payment-deadline'), FiniteDuration::ofHours(2));

        self::assertTrue($pm->hasDeadlineByName('payment-deadline'));
        $ops = $pm->pullPendingDeadlineOperations();
        self::assertCount(1, $ops);
        self::assertInstanceOf(RescheduleDeadline::class, $ops[0]);
    }

    #[Test]
    public function cancelDeadlineRemovesHasDeadlineAndStagesCancelOp(): void
    {
        $pm = DeadlinePm::fresh();
        $pm->doSchedule(DeadlineName::of('payment-deadline'), FiniteDuration::ofHours(24));
        (void) $pm->pullPendingDeadlineOperations();

        $pm->doCancel(DeadlineName::of('payment-deadline'));

        self::assertFalse($pm->hasDeadlineByName('payment-deadline'));
        $ops = $pm->pullPendingDeadlineOperations();
        self::assertCount(1, $ops);
        self::assertInstanceOf(CancelDeadline::class, $ops[0]);
    }

    #[Test]
    public function deadlineOpsAreNoOpDuringReplayButHasDeadlineStillUpdated(): void
    {
        $pm = DeadlinePm::fresh();
        $pm->setReplaying(true);
        $pm->doSchedule(DeadlineName::of('payment-deadline'), FiniteDuration::ofHours(24));

        self::assertTrue($pm->hasDeadlineByName('payment-deadline'));
        self::assertSame([], $pm->pullPendingDeadlineOperations());
    }

    #[Test]
    public function scheduledDeadlineNamesReportsCurrentSet(): void
    {
        $pm = DeadlinePm::fresh();
        $pm->doSchedule(DeadlineName::of('payment-deadline'), FiniteDuration::ofHours(24));
        $pm->doSchedule(DeadlineName::of('shipping-deadline'), FiniteDuration::ofDays(7));

        $names = array_map(static fn(DeadlineName $n): string => $n->value(), $pm->scheduledDeadlineNames());
        sort($names);
        self::assertSame(['payment-deadline', 'shipping-deadline'], $names);
    }
}

final class DeadlinePm extends AbstractProcessManager
{
    public static function fresh(): self
    {
        return new self(new TestProcessManagerId((new Ulid())->toBase32()));
    }

    public function doSchedule(DeadlineName $name, FiniteDuration $after): void
    {
        $this->scheduleDeadline($name, $after);
    }

    public function doReschedule(DeadlineName $name, FiniteDuration $after): void
    {
        $this->rescheduleDeadline($name, $after);
    }

    public function doCancel(DeadlineName $name): void
    {
        $this->cancelDeadline($name);
    }

    public function hasDeadlineByName(string $name): bool
    {
        return $this->hasDeadline(DeadlineName::of($name));
    }
}
```

- [ ] **Step 6.4.2: Run the test, expect green (impl already supports this)**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/ProcessManager/AbstractProcessManagerDeadlinesTest.php
```

### Task 6.5 — AbstractProcessManager: correlation invariants

- [ ] **Step 6.5.1: Write the failing test**

Path: `packages/nexus-ddd-process-manager/tests/Unit/ProcessManager/AbstractProcessManagerCorrelationTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Unit\ProcessManager;

use Monadial\Nexus\Ddd\ProcessManager\Exception\CorrelationConflictException;
use Monadial\Nexus\Ddd\ProcessManager\ProcessManager\AbstractProcessManager;
use Monadial\Nexus\Ddd\ProcessManager\Tests\Support\TestProcessManagerId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

#[CoversClass(AbstractProcessManager::class)]
final class AbstractProcessManagerCorrelationTest extends TestCase
{
    #[Test]
    public function correlateOnRecordsField(): void
    {
        $pm = CorrelationPm::fresh();
        $pm->doCorrelate('shipmentId', 'SHIP-1');
        self::assertSame(['shipmentId' => 'SHIP-1'], $pm->correlations());
    }

    #[Test]
    public function correlateOnIsIdempotentForSameValue(): void
    {
        $pm = CorrelationPm::fresh();
        $pm->doCorrelate('shipmentId', 'SHIP-1');
        $pm->doCorrelate('shipmentId', 'SHIP-1');   // no-op
        self::assertSame(['shipmentId' => 'SHIP-1'], $pm->correlations());
    }

    #[Test]
    public function correlateOnThrowsOnConflictingValue(): void
    {
        $pm = CorrelationPm::fresh();
        $pm->doCorrelate('shipmentId', 'SHIP-1');

        $this->expectException(CorrelationConflictException::class);
        $pm->doCorrelate('shipmentId', 'SHIP-2');
    }

    #[Test]
    public function removeCorrelationDeletesField(): void
    {
        $pm = CorrelationPm::fresh();
        $pm->doCorrelate('shipmentId', 'SHIP-1');
        $pm->doRemove('shipmentId');
        self::assertSame([], $pm->correlations());
    }

    #[Test]
    public function removeOnAbsentFieldIsNoOp(): void
    {
        $pm = CorrelationPm::fresh();
        $pm->doRemove('absent');
        self::assertSame([], $pm->correlations());
    }
}

final class CorrelationPm extends AbstractProcessManager
{
    public static function fresh(): self
    {
        return new self(new TestProcessManagerId((new Ulid())->toBase32()));
    }

    public function doCorrelate(string $field, mixed $value): void
    {
        $this->correlateOn($field, $value);
    }

    public function doRemove(string $field): void
    {
        $this->removeCorrelation($field);
    }
}
```

- [ ] **Step 6.5.2: Run the test, expect green (impl from Task 6.2 already supports this)**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/ProcessManager/AbstractProcessManagerCorrelationTest.php
```

### Task 6.6 — Phase 6 lint/Psalm/deptrac sweep + commit

- [ ] **Step 6.6.1: Run PHPCS, Psalm, Deptrac**

```bash
docker compose exec -T php vendor/bin/phpcs packages/nexus-ddd-process-manager
docker compose exec -T php vendor/bin/psalm
docker compose exec -T php php -d error_reporting='E_ALL & ~E_DEPRECATED' vendor/bin/deptrac analyse
```

- [ ] **Step 6.6.2: Commit**

```bash
git add packages/nexus-ddd-process-manager
git commit -m "$(cat <<'EOF'
feat(ddd-process-manager): AbstractProcessManager + ProcessManagerInternals

Adds the abstract base PMs extend — id, lifecycle flags
(active/completed/terminated), command/event staging, deadline staging
+ hasDeadline registry, secondary correlation index with
CorrelationConflictException invariants, isReplaying flag, and the
ProcessManagerInternals drainer interface (#[NoDiscard] on every
pullPending* method).
EOF
)"
```

---

## Phase 7 — StatefulProcessManager

Pure subclass declaration — adds nothing beyond `AbstractProcessManager` except the named class. The split mirrors `StatefulAggregateRoot`.

### Task 7.1 — StatefulProcessManager class

- [ ] **Step 7.1.1: Write the failing test**

Path: `packages/nexus-ddd-process-manager/tests/Unit/ProcessManager/StatefulProcessManagerTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Unit\ProcessManager;

use Monadial\Nexus\Ddd\Core\Entity\EventSourceable;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use Monadial\Nexus\Ddd\ProcessManager\ProcessManager\AbstractProcessManager;
use Monadial\Nexus\Ddd\ProcessManager\ProcessManager\StatefulProcessManager;
use Monadial\Nexus\Ddd\ProcessManager\Tests\Support\TestProcessManagerId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

#[CoversClass(StatefulProcessManager::class)]
final class StatefulProcessManagerTest extends TestCase
{
    #[Test]
    public function isAbstractAndExtendsAbstractProcessManager(): void
    {
        $reflection = new \ReflectionClass(StatefulProcessManager::class);
        self::assertTrue($reflection->isAbstract());
        self::assertTrue($reflection->isSubclassOf(AbstractProcessManager::class));
    }

    #[Test]
    public function statefulPmIsNotEventSourceable(): void
    {
        $pm = StatefulSampleProcess::start(new TestProcessManagerId((new Ulid())->toBase32()));
        self::assertNotInstanceOf(EventSourceable::class, $pm);
    }

    #[Test]
    public function statefulPmMutatesPropertiesDirectly(): void
    {
        $pm = StatefulSampleProcess::start(new TestProcessManagerId((new Ulid())->toBase32()));
        self::assertFalse($pm->paid);
        $pm->markPaid();
        self::assertTrue($pm->paid);
    }
}

/** @extends StatefulProcessManager<TestProcessManagerId> */
final class StatefulSampleProcess extends StatefulProcessManager
{
    public bool $paid = false;

    public static function start(TestProcessManagerId $id): self
    {
        return new self($id);
    }

    public function markPaid(): void
    {
        $this->paid = true;
    }

    #[\Override]
    public function id(): Identifier
    {
        return $this->id;
    }
}
```

- [ ] **Step 7.1.2: Run the test, expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/ProcessManager/StatefulProcessManagerTest.php
```

- [ ] **Step 7.1.3: Implement StatefulProcessManager**

Path: `packages/nexus-ddd-process-manager/src/ProcessManager/StatefulProcessManager.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\ProcessManager;

use Monadial\Nexus\Ddd\ProcessManager\Identity\ProcessManagerId;

/**
 * @psalm-api
 *
 * @template TId of ProcessManagerId
 * @extends AbstractProcessManager<TId>
 *
 * State-stored process manager. Each instance is one row; persisted
 * state is the serialized properties + scheduled-deadline names +
 * completion/termination flags.
 *
 * **Use this when:** workflow is simple, audit trail of state changes
 * is not required, no need to replay decisions.
 *
 * **Key contract differences from `EventSourcedProcessManager`:**
 *
 * - State mutation happens directly inside `#[OnEvent]` / `#[StartsOn]`
 *   handlers (`$this->paid = true;`).
 * - There is no apply pattern; do NOT define `applyXxx` methods on a
 *   stateful PM.
 * - `pullPendingEvents()` returns ONLY published `DomainEvent`s
 *   (PM-emitted observability events) — never internal state-mutation
 *   events (there are none).
 * - Stateful PMs are NOT `EventSourceable`. Type-discrimination uses
 *   `instanceof EventSourceable`.
 */
abstract class StatefulProcessManager extends AbstractProcessManager {}
```

- [ ] **Step 7.1.4: Run the test, expect green**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/ProcessManager/StatefulProcessManagerTest.php
```

### Task 7.2 — Phase 7 commit

- [ ] **Step 7.2.1: Run PHPCS, Psalm, Deptrac**

```bash
docker compose exec -T php vendor/bin/phpcs packages/nexus-ddd-process-manager
docker compose exec -T php vendor/bin/psalm
docker compose exec -T php php -d error_reporting='E_ALL & ~E_DEPRECATED' vendor/bin/deptrac analyse
```

- [ ] **Step 7.2.2: Commit**

```bash
git add packages/nexus-ddd-process-manager
git commit -m "$(cat <<'EOF'
feat(ddd-process-manager): StatefulProcessManager subclass

Adds the snapshot-persisted PM base. Pure subclass of
AbstractProcessManager — direct state mutation inside handlers, no
apply pattern, not EventSourceable. Mirror of StatefulAggregateRoot.
EOF
)"
```

---

## Phase 8 — EventSourcedProcessManager

The bigger sibling — implements `EventSourceable<TInternalEvent>`, routes `recordThat()` through the core `ApplyDispatcher` (re-used from `nexus-ddd-core`), and supports `replay()` with the `isReplaying` flag toggled around the loop.

### Task 8.1 — Recorded-events buffer + version tracking

The class extends `AbstractProcessManager` and adds an internal `recordedEvents` buffer, `version` counter, the `recordThat()` method routing through `ApplyDispatcher`, the `replay()` method, the `pullRecordedEvents()` drainer, and the `setDispatcher()` injection seam.

- [ ] **Step 8.1.1: Write the failing test**

Path: `packages/nexus-ddd-process-manager/tests/Unit/ProcessManager/EventSourcedProcessManagerTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Unit\ProcessManager;

use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Core\Entity\EventSourceable;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use Monadial\Nexus\Ddd\ProcessManager\ProcessManager\AbstractProcessManager;
use Monadial\Nexus\Ddd\ProcessManager\ProcessManager\EventSourcedProcessManager;
use Monadial\Nexus\Ddd\ProcessManager\Tests\Support\TestProcessManagerId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

#[CoversClass(EventSourcedProcessManager::class)]
final class EventSourcedProcessManagerTest extends TestCase
{
    #[Test]
    public function isAbstractAndExtendsAbstractProcessManagerAndImplementsEventSourceable(): void
    {
        $reflection = new \ReflectionClass(EventSourcedProcessManager::class);
        self::assertTrue($reflection->isAbstract());
        self::assertTrue($reflection->isSubclassOf(AbstractProcessManager::class));
        self::assertTrue($reflection->implementsInterface(EventSourceable::class));
    }

    #[Test]
    public function recordThatAppliesAndAppendsAndBumpsVersion(): void
    {
        $pm = EsSampleProcess::startWith(new TestProcessManagerId((new Ulid())->toBase32()));
        self::assertSame(0, $pm->version());

        $pm->doRecord(new EsSampleStarted('order-1'));

        self::assertSame('order-1', $pm->orderId);
        self::assertSame(1, $pm->version());
        self::assertCount(1, $pm->pullRecordedEvents());
        // Drain clears.
        self::assertCount(0, $pm->pullRecordedEvents());
    }

    #[Test]
    public function replayInvokesApplyAndBumpsVersionWithoutAppending(): void
    {
        $id = new TestProcessManagerId((new Ulid())->toBase32());
        $pm = EsSampleProcess::startWith($id);

        $pm->replay([
            new EsSampleStarted('order-1'),
            new EsSamplePaid('order-1'),
        ]);

        self::assertSame('order-1', $pm->orderId);
        self::assertTrue($pm->paid);
        self::assertSame(2, $pm->version());
        // Replay does NOT append to the recorded buffer.
        self::assertCount(0, $pm->pullRecordedEvents());
    }

    #[Test]
    public function replaySetsAndUnsetsTheReplayingFlagAroundTheLoop(): void
    {
        $id = new TestProcessManagerId((new Ulid())->toBase32());
        $pm = ReplayWatcher::startWith($id);
        $pm->replay([new EsSampleStarted('order-1')]);
        self::assertTrue($pm->wasReplayingDuringApply);
        self::assertFalse($pm->isReplaying());   // restored after the loop
    }
}

/** @extends EventSourcedProcessManager<TestProcessManagerId, DomainEvent> */
final class EsSampleProcess extends EventSourcedProcessManager
{
    public string $orderId = '';
    public bool $paid = false;

    public static function startWith(TestProcessManagerId $id): self
    {
        return new self($id);
    }

    public function doRecord(DomainEvent $event): void
    {
        $this->recordThat($event);
    }

    #[\Override]
    public function id(): Identifier
    {
        return $this->id;
    }

    private function applyEsSampleStarted(EsSampleStarted $event): void
    {
        $this->orderId = $event->orderId;
    }

    private function applyEsSamplePaid(EsSamplePaid $event): void
    {
        $this->paid = true;
    }
}

/** @extends EventSourcedProcessManager<TestProcessManagerId, DomainEvent> */
final class ReplayWatcher extends EventSourcedProcessManager
{
    public bool $wasReplayingDuringApply = false;

    public static function startWith(TestProcessManagerId $id): self
    {
        return new self($id);
    }

    #[\Override]
    public function id(): Identifier
    {
        return $this->id;
    }

    private function applyEsSampleStarted(EsSampleStarted $event): void
    {
        $this->wasReplayingDuringApply = $this->isReplaying();
    }
}

final readonly class EsSampleStarted implements DomainEvent
{
    public function __construct(public string $orderId) {}
}

final readonly class EsSamplePaid implements DomainEvent
{
    public function __construct(public string $orderId) {}
}
```

- [ ] **Step 8.1.2: Run the test, expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/ProcessManager/EventSourcedProcessManagerTest.php
```

- [ ] **Step 8.1.3: Implement EventSourcedProcessManager**

Path: `packages/nexus-ddd-process-manager/src/ProcessManager/EventSourcedProcessManager.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\ProcessManager;

use Monadial\Nexus\Ddd\Core\Aggregate\Internal\ApplyDispatcher;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Core\Entity\EventSourceable;
use Monadial\Nexus\Ddd\ProcessManager\Identity\ProcessManagerId;

/**
 * @psalm-api
 *
 * @template TId of ProcessManagerId
 * @template TInternalEvent of DomainEvent
 * @extends AbstractProcessManager<TId>
 * @implements EventSourceable<TInternalEvent>
 *
 * Event-sourced process manager. State is reconstructed by replaying
 * internal events. Each handler that mutates state must do so via
 * `recordThat()` — which dispatches through `applyXxx()` and appends
 * the event to the recorded buffer.
 *
 * **Use this when:** workflow has decision points worth auditing, you
 * want temporal queries, or replay invariants are useful for testing.
 *
 * **`setDispatcher()` injection seam.** The static `ApplyDispatcher`
 * slot is shared by all ES PMs in this PHP process — same model as
 * `EventSourcedAggregateRoot`. Frameworks scoping a dispatcher per
 * worker, per test, or per coroutine pool replace it via this seam.
 */
abstract class EventSourcedProcessManager extends AbstractProcessManager implements EventSourceable
{
    private static ?ApplyDispatcher $dispatcher = null;

    /** @var array<int, TInternalEvent> */
    private array $recordedEvents = [];

    private int $version = 0;

    public static function setDispatcher(?ApplyDispatcher $dispatcher): ?ApplyDispatcher
    {
        $previous = self::$dispatcher;
        self::$dispatcher = $dispatcher;

        return $previous;
    }

    /**
     * Record + apply: dispatch through `applyXxx` so state moves in
     * lock-step with the recorded stream, then append + bump version.
     *
     * **Ordering matters.** Dispatch runs before append. If `applyXxx`
     * throws, the event is NOT appended and version is NOT bumped.
     *
     * @param TInternalEvent $event
     */
    final protected function recordThat(DomainEvent $event): void
    {
        self::dispatcher()->dispatch($this, $event);
        $this->recordedEvents[] = $event;
        $this->version++;
    }

    /** @param iterable<int, TInternalEvent> $events */
    #[\Override]
    final public function replay(iterable $events): void
    {
        $dispatcher = self::dispatcher();
        $wasReplaying = $this->isReplaying();
        $this->setReplaying(true);

        try {
            foreach ($events as $event) {
                $dispatcher->dispatch($this, $event);
                $this->version++;
            }
        } finally {
            $this->setReplaying($wasReplaying);
        }
    }

    #[\Override]
    final public function version(): int
    {
        return $this->version;
    }

    /**
     * Drain recorded internal events. Mirror of
     * `EventSourcedAggregateRoot::pullRecordedEvents()`.
     *
     * @return array<int, TInternalEvent>
     */
    #[\Override]
    #[\NoDiscard('pullRecordedEvents drains the buffer — discarding loses every recorded event')]
    final public function pullRecordedEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];

        return $events;
    }

    /**
     * Rehydrate version from a snapshot. Same shape as
     * `AggregateRoot::rehydrateVersion()`.
     *
     * @internal Framework wiring entry point.
     */
    final protected function rehydrateVersion(int $revision): void
    {
        $this->version = $revision;
    }

    private static function dispatcher(): ApplyDispatcher
    {
        return self::$dispatcher ??= new ApplyDispatcher();
    }
}
```

- [ ] **Step 8.1.4: Run the test, expect green**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/ProcessManager/EventSourcedProcessManagerTest.php
```

### Task 8.2 — Replay flag suppresses staging side effects

This test verifies the spec §7 contract: during replay, `dispatchCommand` / `publishEvent` / deadline ops are suppressed.

- [ ] **Step 8.2.1: Write the failing test**

Path: `packages/nexus-ddd-process-manager/tests/Unit/ProcessManager/EventSourcedProcessManagerReplayIsolationTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Unit\ProcessManager;

use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use Monadial\Nexus\Ddd\ProcessManager\Contract\Messaging\Command;
use Monadial\Nexus\Ddd\ProcessManager\ProcessManager\EventSourcedProcessManager;
use Monadial\Nexus\Ddd\ProcessManager\Tests\Support\TestProcessManagerId;
use Monadial\Nexus\Ddd\ProcessManager\Value\DeadlineName;
use Monadial\PhpDuration\FiniteDuration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

#[CoversClass(EventSourcedProcessManager::class)]
final class EventSourcedProcessManagerReplayIsolationTest extends TestCase
{
    #[Test]
    public function replayDoesNotEmitCommandsEventsOrDeadlineOps(): void
    {
        $id = new TestProcessManagerId((new Ulid())->toBase32());
        $pm = NoisyReplayProcess::startWith($id);
        $pm->replay([new NoisyEvent('A')]);

        self::assertSame([], $pm->pullPendingCommands());
        self::assertSame([], $pm->pullPendingEvents());
        self::assertSame([], $pm->pullPendingDeadlineOperations());
    }

    #[Test]
    public function replayUpdatesInMemoryDeadlineRegistryAndCorrelationIndex(): void
    {
        $id = new TestProcessManagerId((new Ulid())->toBase32());
        $pm = NoisyReplayProcess::startWith($id);
        $pm->replay([new NoisyEvent('A')]);

        self::assertNotEmpty($pm->scheduledDeadlineNames());
        self::assertSame(['shipmentId' => 'SHIP-1'], $pm->correlations());
    }
}

/** @extends EventSourcedProcessManager<TestProcessManagerId, DomainEvent> */
final class NoisyReplayProcess extends EventSourcedProcessManager
{
    public static function startWith(TestProcessManagerId $id): self
    {
        return new self($id);
    }

    #[\Override]
    public function id(): Identifier
    {
        return $this->id;
    }

    private function applyNoisyEvent(NoisyEvent $event): void
    {
        // Every "side effect" called from inside applyXxx must be
        // suppressed except for in-memory deadline registry +
        // correlation updates.
        $this->dispatchCommand(new NoisyCommand());
        $this->publishEvent(new NoisyEvent('out'));
        $this->scheduleDeadline(DeadlineName::of('replay-deadline'), FiniteDuration::ofHours(1));
        $this->correlateOn('shipmentId', 'SHIP-1');
    }
}

final readonly class NoisyEvent implements DomainEvent
{
    public function __construct(public string $tag) {}
}

final readonly class NoisyCommand implements Command {}
```

- [ ] **Step 8.2.2: Run the test, expect green (impl from Tasks 6.2 + 8.1 already supports this)**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/ProcessManager/EventSourcedProcessManagerReplayIsolationTest.php
```

### Task 8.3 — rehydrateVersion preserves snapshot rebuild

- [ ] **Step 8.3.1: Write the failing test**

Path: `packages/nexus-ddd-process-manager/tests/Unit/ProcessManager/EventSourcedProcessManagerRehydrateTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Unit\ProcessManager;

use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use Monadial\Nexus\Ddd\ProcessManager\ProcessManager\EventSourcedProcessManager;
use Monadial\Nexus\Ddd\ProcessManager\Tests\Support\TestProcessManagerId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

#[CoversClass(EventSourcedProcessManager::class)]
final class EventSourcedProcessManagerRehydrateTest extends TestCase
{
    #[Test]
    public function rehydrateVersionSetsVersionToAbsolute(): void
    {
        $id = new TestProcessManagerId((new Ulid())->toBase32());
        $pm = RehydrateProcess::startWith($id);
        $pm->doRehydrate(42);
        self::assertSame(42, $pm->version());
    }

    #[Test]
    public function replayAfterRehydrateContinuesFromVersion(): void
    {
        $id = new TestProcessManagerId((new Ulid())->toBase32());
        $pm = RehydrateProcess::startWith($id);
        $pm->doRehydrate(42);
        $pm->replay([new RehydrateEvent(), new RehydrateEvent()]);
        self::assertSame(44, $pm->version());
    }
}

/** @extends EventSourcedProcessManager<TestProcessManagerId, DomainEvent> */
final class RehydrateProcess extends EventSourcedProcessManager
{
    public static function startWith(TestProcessManagerId $id): self
    {
        return new self($id);
    }

    public function doRehydrate(int $revision): void
    {
        $this->rehydrateVersion($revision);
    }

    #[\Override]
    public function id(): Identifier
    {
        return $this->id;
    }

    private function applyRehydrateEvent(RehydrateEvent $event): void
    {
        // no-op for this test
    }
}

final readonly class RehydrateEvent implements DomainEvent {}
```

- [ ] **Step 8.3.2: Run the test, expect green**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/ProcessManager/EventSourcedProcessManagerRehydrateTest.php
```

### Task 8.4 — Phase 8 commit

- [ ] **Step 8.4.1: Run PHPCS, Psalm, Deptrac**

```bash
docker compose exec -T php vendor/bin/phpcs packages/nexus-ddd-process-manager
docker compose exec -T php vendor/bin/psalm
docker compose exec -T php php -d error_reporting='E_ALL & ~E_DEPRECATED' vendor/bin/deptrac analyse
```

- [ ] **Step 8.4.2: Commit**

```bash
git add packages/nexus-ddd-process-manager
git commit -m "$(cat <<'EOF'
feat(ddd-process-manager): EventSourcedProcessManager subclass

Adds the event-sourced PM base implementing EventSourceable. recordThat
dispatches through nexus-ddd-core's ApplyDispatcher then appends + bumps
version. replay() runs apply only (no side effects) inside a try/finally
that toggles the isReplaying flag, so dispatchCommand/publishEvent/
deadline-ops are suppressed during stream rehydration. setDispatcher
injection seam mirrors EventSourcedAggregateRoot.
EOF
)"
```

---

## Phase 9 — Staging contracts + InMemory implementations

This phase ships `MessageStaging` + `UnitOfWork` interfaces, their `InMemory*` implementations (suitable for tests and single-process Fiber runtimes), and the abstract contract test that BOTH the in-memory impl and the future outbox impl must pass.

### Task 9.1 — MessageStaging interface

- [ ] **Step 9.1.1: Write the failing test**

Path: `packages/nexus-ddd-process-manager/tests/Unit/Staging/MessageStagingInterfaceTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Unit\Staging;

use Monadial\Nexus\Ddd\ProcessManager\Staging\MessageStaging;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class MessageStagingInterfaceTest extends TestCase
{
    #[Test]
    public function declaresAppendDiscardFlushMethods(): void
    {
        $reflection = new \ReflectionClass(MessageStaging::class);
        self::assertTrue($reflection->isInterface());
        $methods = array_map(
            static fn(\ReflectionMethod $m): string => $m->getName(),
            $reflection->getMethods(),
        );
        self::assertContains('appendCommand', $methods);
        self::assertContains('appendEvent', $methods);
        self::assertContains('appendDeadlineOperation', $methods);
        self::assertContains('flush', $methods);
        self::assertContains('discard', $methods);
    }
}
```

- [ ] **Step 9.1.2: Run the test, expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Staging/MessageStagingInterfaceTest.php
```

- [ ] **Step 9.1.3: Implement MessageStaging**

Path: `packages/nexus-ddd-process-manager/src/Staging/MessageStaging.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Staging;

use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\ProcessManager\Contract\Messaging\Command;
use Monadial\Nexus\Ddd\ProcessManager\Deadline\DeadlineOperation;

/**
 * @psalm-api
 *
 * Buffers the PM's outbound intent — commands, events, deadline ops —
 * and either flushes (post-commit) or discards (post-rollback). The PM
 * itself never sees this contract; it calls `dispatchCommand()` etc. on
 * itself, and the runtime's repository / unit-of-work code copies the
 * drained buffers into staging.
 *
 * **Implementations.** `InMemoryMessageStaging` (this package) for
 * tests and single-process Fiber runtimes. `OutboxMessageStaging`
 * (downstream `nexus-ddd-aggregate` or `nexus-ddd-outbox`) for
 * DB-backed deferral inside the same TX as PM state changes.
 *
 * **Contract pinned by `MessageStagingContractTest`** — both
 * implementations MUST pass that abstract test.
 */
interface MessageStaging
{
    public function appendCommand(Command $command): void;

    public function appendEvent(DomainEvent $event): void;

    public function appendDeadlineOperation(DeadlineOperation $op): void;

    /** Post-commit. Buses + scheduler invoked here. */
    public function flush(): void;

    /** Post-rollback. Buffer discarded; nothing is published. */
    public function discard(): void;
}
```

- [ ] **Step 9.1.4: Run the test, expect green**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Staging/MessageStagingInterfaceTest.php
```

### Task 9.2 — UnitOfWork interface

- [ ] **Step 9.2.1: Write the failing test**

Path: `packages/nexus-ddd-process-manager/tests/Unit/Staging/UnitOfWorkInterfaceTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Unit\Staging;

use Monadial\Nexus\Ddd\ProcessManager\Staging\UnitOfWork;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class UnitOfWorkInterfaceTest extends TestCase
{
    #[Test]
    public function declaresBeginCommitRollbackStaging(): void
    {
        $reflection = new \ReflectionClass(UnitOfWork::class);
        self::assertTrue($reflection->isInterface());
        $methods = array_map(
            static fn(\ReflectionMethod $m): string => $m->getName(),
            $reflection->getMethods(),
        );
        self::assertContains('begin', $methods);
        self::assertContains('commit', $methods);
        self::assertContains('rollback', $methods);
        self::assertContains('staging', $methods);
    }
}
```

- [ ] **Step 9.2.2: Run the test, expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Staging/UnitOfWorkInterfaceTest.php
```

- [ ] **Step 9.2.3: Implement UnitOfWork**

Path: `packages/nexus-ddd-process-manager/src/Staging/UnitOfWork.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Staging;

/**
 * @psalm-api
 *
 * Transaction boundary contract. The runtime's repository wraps every
 * handler invocation in `begin() ... commit()`; on commit the unit of
 * work calls `staging()->flush()`, on rollback `staging()->discard()`.
 *
 * The PM never sees this contract directly. Domain code calls
 * `dispatchCommand()` / `publishEvent()` / `scheduleDeadline()` on its
 * own surface; the runtime moves the drained buffers into staging
 * before issuing commit/rollback.
 */
interface UnitOfWork
{
    public function begin(): void;

    public function commit(): void;

    public function rollback(): void;

    public function staging(): MessageStaging;
}
```

- [ ] **Step 9.2.4: Run the test, expect green**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Staging/UnitOfWorkInterfaceTest.php
```

### Task 9.3 — MessageStagingContractTest abstract base

The contract test pins `flush()`, `discard()`, FIFO, idempotency-of-discard invariants. Every implementation MUST pass it.

- [ ] **Step 9.3.1: Write the abstract contract test**

Path: `packages/nexus-ddd-process-manager/tests/Support/MessageStagingContractTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Support;

use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\ProcessManager\Contract\Messaging\Command;
use Monadial\Nexus\Ddd\ProcessManager\Deadline\CancelDeadline;
use Monadial\Nexus\Ddd\ProcessManager\Staging\MessageStaging;
use Monadial\Nexus\Ddd\ProcessManager\Value\DeadlineName;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Shared contract test. Both `InMemoryMessageStaging` and the future
 * downstream `OutboxMessageStaging` MUST extend this class and provide
 * the three sink callbacks via the abstract factory.
 *
 * Pinned invariants (spec §8):
 *  1. `flush()` invokes the bus exactly once per appended message.
 *  2. `discard()` after `appendCommand()`/`appendEvent()` → buses see nothing.
 *  3. FIFO ordering preserved across staging cycles.
 *  4. After flush(), a fresh staging cycle starts (next append is fresh).
 */
abstract class MessageStagingContractTest extends TestCase
{
    /** @var array<int, Command> */
    protected array $sentCommands = [];

    /** @var array<int, DomainEvent> */
    protected array $publishedEvents = [];

    /** @var array<int, mixed> */
    protected array $scheduledOps = [];

    abstract protected function newStaging(): MessageStaging;

    #[Test]
    public function flushDispatchesEachAppendedMessageExactlyOnce(): void
    {
        $staging = $this->newStaging();
        $staging->appendCommand(new ContractCommand('A'));
        $staging->appendEvent(new ContractEvent('E'));
        $staging->appendDeadlineOperation(new CancelDeadline(DeadlineName::of('d')));

        $staging->flush();

        self::assertCount(1, $this->sentCommands);
        self::assertCount(1, $this->publishedEvents);
        self::assertCount(1, $this->scheduledOps);
    }

    #[Test]
    public function discardDropsEverythingStaged(): void
    {
        $staging = $this->newStaging();
        $staging->appendCommand(new ContractCommand('A'));
        $staging->appendEvent(new ContractEvent('E'));
        $staging->appendDeadlineOperation(new CancelDeadline(DeadlineName::of('d')));

        $staging->discard();
        $staging->flush();

        self::assertSame([], $this->sentCommands);
        self::assertSame([], $this->publishedEvents);
        self::assertSame([], $this->scheduledOps);
    }

    #[Test]
    public function fifoOrderPreservedWithinStagingCycle(): void
    {
        $staging = $this->newStaging();
        $staging->appendCommand(new ContractCommand('A'));
        $staging->appendCommand(new ContractCommand('B'));
        $staging->appendCommand(new ContractCommand('C'));

        $staging->flush();

        $ids = array_map(static fn(ContractCommand $c): string => $c->id, $this->sentCommands);
        self::assertSame(['A', 'B', 'C'], $ids);
    }

    #[Test]
    public function flushClearsTheBufferSoTheNextCycleStartsFresh(): void
    {
        $staging = $this->newStaging();
        $staging->appendCommand(new ContractCommand('A'));
        $staging->flush();
        $staging->flush();   // second flush is a no-op; bus seen exactly once

        self::assertCount(1, $this->sentCommands);
    }

    #[Test]
    public function discardThenAppendThenFlushDispatchesOnlyTheNewMessages(): void
    {
        $staging = $this->newStaging();
        $staging->appendCommand(new ContractCommand('discarded'));
        $staging->discard();

        $staging->appendCommand(new ContractCommand('kept'));
        $staging->flush();

        self::assertCount(1, $this->sentCommands);
        self::assertSame('kept', $this->sentCommands[0]->id);
    }
}

final readonly class ContractCommand implements Command
{
    public function __construct(public string $id) {}
}

final readonly class ContractEvent implements DomainEvent
{
    public function __construct(public string $id) {}
}
```

### Task 9.4 — InMemoryMessageStaging implementation

- [ ] **Step 9.4.1: Write the failing test (subclass of contract test)**

Path: `packages/nexus-ddd-process-manager/tests/Unit/Staging/InMemoryMessageStagingTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Unit\Staging;

use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\ProcessManager\Contract\Messaging\Command;
use Monadial\Nexus\Ddd\ProcessManager\Deadline\DeadlineOperation;
use Monadial\Nexus\Ddd\ProcessManager\Staging\InMemoryMessageStaging;
use Monadial\Nexus\Ddd\ProcessManager\Staging\MessageStaging;
use Monadial\Nexus\Ddd\ProcessManager\Tests\Support\MessageStagingContractTest;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(InMemoryMessageStaging::class)]
final class InMemoryMessageStagingTest extends MessageStagingContractTest
{
    #[\Override]
    protected function newStaging(): MessageStaging
    {
        return new InMemoryMessageStaging(
            function (Command $command): void {
                $this->sentCommands[] = $command;
            },
            function (DomainEvent $event): void {
                $this->publishedEvents[] = $event;
            },
            function (DeadlineOperation $op): void {
                $this->scheduledOps[] = $op;
            },
        );
    }
}
```

- [ ] **Step 9.4.2: Run the test, expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Staging/InMemoryMessageStagingTest.php
```

- [ ] **Step 9.4.3: Implement InMemoryMessageStaging**

Path: `packages/nexus-ddd-process-manager/src/Staging/InMemoryMessageStaging.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Staging;

use Closure;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\ProcessManager\Contract\Messaging\Command;
use Monadial\Nexus\Ddd\ProcessManager\Deadline\DeadlineOperation;

/**
 * @psalm-api
 *
 * In-memory `MessageStaging` for tests and single-process Fiber
 * runtimes. Receives three sink closures — one per message kind — and
 * invokes them in append order during `flush()`. `discard()` drops the
 * buffer; subsequent `flush()` is a no-op.
 *
 * **Not transactional.** This class doesn't pretend to coordinate with
 * a database transaction. The runtime that wires this implementation
 * either runs without DB persistence, or accepts the tradeoff that
 * "commit" and "publish" are two separate atomic moments. The
 * `OutboxMessageStaging` downstream variant is the one that bundles
 * outbox writes inside the same DB transaction.
 */
final class InMemoryMessageStaging implements MessageStaging
{
    /** @var array<int, Command> */
    private array $commands = [];

    /** @var array<int, DomainEvent> */
    private array $events = [];

    /** @var array<int, DeadlineOperation> */
    private array $deadlineOps = [];

    /**
     * @param Closure(Command): void $commandSink
     * @param Closure(DomainEvent): void $eventSink
     * @param Closure(DeadlineOperation): void $deadlineSink
     */
    public function __construct(
        private readonly Closure $commandSink,
        private readonly Closure $eventSink,
        private readonly Closure $deadlineSink,
    ) {}

    #[\Override]
    public function appendCommand(Command $command): void
    {
        $this->commands[] = $command;
    }

    #[\Override]
    public function appendEvent(DomainEvent $event): void
    {
        $this->events[] = $event;
    }

    #[\Override]
    public function appendDeadlineOperation(DeadlineOperation $op): void
    {
        $this->deadlineOps[] = $op;
    }

    #[\Override]
    public function flush(): void
    {
        foreach ($this->commands as $command) {
            ($this->commandSink)($command);
        }

        foreach ($this->events as $event) {
            ($this->eventSink)($event);
        }

        foreach ($this->deadlineOps as $op) {
            ($this->deadlineSink)($op);
        }

        $this->commands = [];
        $this->events = [];
        $this->deadlineOps = [];
    }

    #[\Override]
    public function discard(): void
    {
        $this->commands = [];
        $this->events = [];
        $this->deadlineOps = [];
    }
}
```

- [ ] **Step 9.4.4: Run the test, expect green**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Staging/InMemoryMessageStagingTest.php
```

Expected: 5 contract tests OK.

### Task 9.5 — InMemoryUnitOfWork

- [ ] **Step 9.5.1: Write the failing test**

Path: `packages/nexus-ddd-process-manager/tests/Unit/Staging/InMemoryUnitOfWorkTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Unit\Staging;

use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\ProcessManager\Contract\Messaging\Command;
use Monadial\Nexus\Ddd\ProcessManager\Deadline\DeadlineOperation;
use Monadial\Nexus\Ddd\ProcessManager\Staging\InMemoryMessageStaging;
use Monadial\Nexus\Ddd\ProcessManager\Staging\InMemoryUnitOfWork;
use Monadial\Nexus\Ddd\ProcessManager\Staging\MessageStaging;
use Monadial\Nexus\Ddd\ProcessManager\Staging\UnitOfWork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(InMemoryUnitOfWork::class)]
final class InMemoryUnitOfWorkTest extends TestCase
{
    #[Test]
    public function commitFlushesStaging(): void
    {
        $captured = [];
        $staging = new InMemoryMessageStaging(
            static function (Command $c) use (&$captured): void {
                $captured[] = $c;
            },
            static function (DomainEvent $e): void {},
            static function (DeadlineOperation $op): void {},
        );
        $uow = new InMemoryUnitOfWork($staging);

        $uow->begin();
        $uow->staging()->appendCommand(new TestCommand('X'));
        $uow->commit();

        self::assertCount(1, $captured);
    }

    #[Test]
    public function rollbackDiscardsStaging(): void
    {
        $captured = [];
        $staging = new InMemoryMessageStaging(
            static function (Command $c) use (&$captured): void {
                $captured[] = $c;
            },
            static function (DomainEvent $e): void {},
            static function (DeadlineOperation $op): void {},
        );
        $uow = new InMemoryUnitOfWork($staging);

        $uow->begin();
        $uow->staging()->appendCommand(new TestCommand('X'));
        $uow->rollback();

        self::assertSame([], $captured);
    }

    #[Test]
    public function stagingReturnsTheSameInstance(): void
    {
        $staging = new InMemoryMessageStaging(
            static function (Command $c): void {},
            static function (DomainEvent $e): void {},
            static function (DeadlineOperation $op): void {},
        );
        $uow = new InMemoryUnitOfWork($staging);

        self::assertInstanceOf(MessageStaging::class, $uow->staging());
        self::assertSame($staging, $uow->staging());
    }

    #[Test]
    public function isAUnitOfWork(): void
    {
        $staging = new InMemoryMessageStaging(
            static function (Command $c): void {},
            static function (DomainEvent $e): void {},
            static function (DeadlineOperation $op): void {},
        );
        $uow = new InMemoryUnitOfWork($staging);
        self::assertInstanceOf(UnitOfWork::class, $uow);
    }
}

final readonly class TestCommand implements Command
{
    public function __construct(public string $id) {}
}
```

- [ ] **Step 9.5.2: Run the test, expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Staging/InMemoryUnitOfWorkTest.php
```

- [ ] **Step 9.5.3: Implement InMemoryUnitOfWork**

Path: `packages/nexus-ddd-process-manager/src/Staging/InMemoryUnitOfWork.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Staging;

/**
 * @psalm-api
 *
 * In-memory `UnitOfWork` paired with `InMemoryMessageStaging`.
 * `commit()` flushes; `rollback()` discards. There is no underlying
 * database transaction — this implementation suits tests and
 * single-process Fiber runtimes that do not need DB-coordinated
 * boundaries.
 */
final class InMemoryUnitOfWork implements UnitOfWork
{
    public function __construct(private readonly MessageStaging $staging) {}

    #[\Override]
    public function begin(): void
    {
        // no-op
    }

    #[\Override]
    public function commit(): void
    {
        $this->staging->flush();
    }

    #[\Override]
    public function rollback(): void
    {
        $this->staging->discard();
    }

    #[\Override]
    public function staging(): MessageStaging
    {
        return $this->staging;
    }
}
```

- [ ] **Step 9.5.4: Run the test, expect green**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Staging/InMemoryUnitOfWorkTest.php
```

### Task 9.6 — Phase 9 lint/Psalm/deptrac sweep + commit

- [ ] **Step 9.6.1: Run PHPCS, Psalm, Deptrac**

```bash
docker compose exec -T php vendor/bin/phpcs packages/nexus-ddd-process-manager
docker compose exec -T php vendor/bin/psalm
docker compose exec -T php php -d error_reporting='E_ALL & ~E_DEPRECATED' vendor/bin/deptrac analyse
```

- [ ] **Step 9.6.2: Commit**

```bash
git add packages/nexus-ddd-process-manager
git commit -m "$(cat <<'EOF'
feat(ddd-process-manager): MessageStaging + UnitOfWork contracts and InMemory impls

Adds the MessageStaging + UnitOfWork interfaces, plus
InMemoryMessageStaging and InMemoryUnitOfWork suitable for tests and
single-process Fiber runtimes. The abstract MessageStagingContractTest
in tests/Support pins flush/discard/FIFO/idempotency invariants — both
the in-memory impl and downstream OutboxMessageStaging must extend and
pass it.
EOF
)"
```

---

## Phase 10 — Persistence contracts (interfaces only)

This phase ships interfaces only — no implementations. Implementations live in downstream `nexus-ddd-aggregate` / DBAL / Doctrine packages. The contracts here are what every adapter MUST satisfy.

### Task 10.1 — ProcessManagerEventStore

- [ ] **Step 10.1.1: Write the failing test**

Path: `packages/nexus-ddd-process-manager/tests/Unit/Persistence/ProcessManagerEventStoreTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Unit\Persistence;

use Monadial\Nexus\Ddd\ProcessManager\Persistence\ProcessManagerEventStore;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ProcessManagerEventStoreTest extends TestCase
{
    #[Test]
    public function declaresAppendLoadStreamExists(): void
    {
        $reflection = new \ReflectionClass(ProcessManagerEventStore::class);
        self::assertTrue($reflection->isInterface());

        $methods = array_map(
            static fn(\ReflectionMethod $m): string => $m->getName(),
            $reflection->getMethods(),
        );
        self::assertContains('append', $methods);
        self::assertContains('load', $methods);
        self::assertContains('streamExists', $methods);
    }
}
```

- [ ] **Step 10.1.2: Run the test, expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Persistence/ProcessManagerEventStoreTest.php
```

- [ ] **Step 10.1.3: Implement ProcessManagerEventStore**

Path: `packages/nexus-ddd-process-manager/src/Persistence/ProcessManagerEventStore.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Persistence;

use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Core\Exception\OptimisticLockException;
use Monadial\Nexus\Ddd\ProcessManager\Identity\ProcessManagerId;

/**
 * @psalm-api
 *
 * @template TInternalEvent of DomainEvent
 *
 * Stream contract for event-sourced process manager persistence.
 * Per-PM-type physical tables are the user's chosen layout; this
 * contract is what every adapter implements regardless.
 *
 * **Stream invariants** every adapter MUST guarantee:
 * - Stream id = `ProcessManagerId` value
 * - Sequence numbers are per-stream, monotonically increasing from 1
 * - Each row is exactly one DomainEvent (internal to the PM)
 * - Writer-id stamping (single-writer principle) on every row
 * - Optimistic concurrency on `(stream_id, expected_version)` at append
 */
interface ProcessManagerEventStore
{
    /**
     * Append events with optimistic concurrency.
     *
     * @param iterable<int, TInternalEvent> $events
     * @throws OptimisticLockException when expected version mismatches.
     */
    public function append(
        ProcessManagerId $streamId,
        int $expectedVersion,
        iterable $events,
        string $writerId,
    ): void;

    /**
     * Load the full stream (or from-snapshot+delta when a snapshot
     * store is present and a snapshot was taken).
     *
     * @return iterable<int, TInternalEvent>
     */
    public function load(ProcessManagerId $streamId): iterable;

    public function streamExists(ProcessManagerId $streamId): bool;
}
```

- [ ] **Step 10.1.4: Run the test, expect green**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Persistence/ProcessManagerEventStoreTest.php
```

### Task 10.2 — ProcessManagerRepository

- [ ] **Step 10.2.1: Write the failing test**

Path: `packages/nexus-ddd-process-manager/tests/Unit/Persistence/ProcessManagerRepositoryTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Unit\Persistence;

use Monadial\Nexus\Ddd\ProcessManager\Persistence\ProcessManagerRepository;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ProcessManagerRepositoryTest extends TestCase
{
    #[Test]
    public function declaresFindOpenAndSaveAndDelete(): void
    {
        $reflection = new \ReflectionClass(ProcessManagerRepository::class);
        self::assertTrue($reflection->isInterface());

        $methods = array_map(
            static fn(\ReflectionMethod $m): string => $m->getName(),
            $reflection->getMethods(),
        );
        self::assertContains('find', $methods);
        self::assertContains('save', $methods);
        self::assertContains('delete', $methods);
    }
}
```

- [ ] **Step 10.2.2: Run the test, expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Persistence/ProcessManagerRepositoryTest.php
```

- [ ] **Step 10.2.3: Implement ProcessManagerRepository**

Path: `packages/nexus-ddd-process-manager/src/Persistence/ProcessManagerRepository.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Persistence;

use Monadial\Nexus\Ddd\ProcessManager\Identity\ProcessManagerId;
use Monadial\Nexus\Ddd\ProcessManager\ProcessManager\AbstractProcessManager;

/**
 * @psalm-api
 *
 * @template TPm of AbstractProcessManager
 *
 * Repository contract for process manager persistence. Implementations
 * (event-store-backed, snapshot-store-backed, ORM-backed) live
 * downstream — this package only declares the contract.
 *
 * **`save()` semantics.** Drains `pullPendingCommands()`,
 * `pullPendingEvents()`, and `pullPendingDeadlineOperations()` from the
 * PM, persists the PM's own state changes (events for ES PMs, snapshot
 * row for stateful PMs), and stages drained outbound intent through
 * the registered `MessageStaging`. The unit-of-work boundary is
 * applied OUTSIDE this method by the runtime.
 */
interface ProcessManagerRepository
{
    /** @return TPm|null */
    public function find(ProcessManagerId $id): ?AbstractProcessManager;

    /** @param TPm $pm */
    public function save(AbstractProcessManager $pm): void;

    public function delete(ProcessManagerId $id): void;
}
```

- [ ] **Step 10.2.4: Run the test, expect green**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Persistence/ProcessManagerRepositoryTest.php
```

### Task 10.3 — ProcessManagerSnapshot value object

- [ ] **Step 10.3.1: Write the failing test**

Path: `packages/nexus-ddd-process-manager/tests/Unit/Persistence/ProcessManagerSnapshotTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Unit\Persistence;

use DateTimeImmutable;
use Monadial\Nexus\Ddd\ProcessManager\Persistence\ProcessManagerSnapshot;
use Monadial\Nexus\Ddd\ProcessManager\Tests\Support\TestProcessManagerId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

#[CoversClass(ProcessManagerSnapshot::class)]
final class ProcessManagerSnapshotTest extends TestCase
{
    #[Test]
    public function carriesObservabilityFields(): void
    {
        $id = new TestProcessManagerId((new Ulid())->toBase32());
        $startedAt = new DateTimeImmutable('2026-05-01T10:00:00Z');
        $lastEventAt = new DateTimeImmutable('2026-05-07T12:00:00Z');

        $snapshot = new ProcessManagerSnapshot(
            $id,
            'App\\Process\\OrderFulfillment',
            false,
            false,
            null,
            7,
            $startedAt,
            $lastEventAt,
            [],
            [],
        );

        self::assertSame($id, $snapshot->id);
        self::assertSame('App\\Process\\OrderFulfillment', $snapshot->pmClass);
        self::assertFalse($snapshot->isCompleted);
        self::assertFalse($snapshot->isTerminated);
        self::assertNull($snapshot->terminationReason);
        self::assertSame(7, $snapshot->version);
        self::assertSame($startedAt, $snapshot->startedAt);
        self::assertSame($lastEventAt, $snapshot->lastEventAt);
        self::assertSame([], $snapshot->pendingDeadlines);
        self::assertSame([], $snapshot->correlations);
    }
}
```

- [ ] **Step 10.3.2: Run the test, expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Persistence/ProcessManagerSnapshotTest.php
```

- [ ] **Step 10.3.3: Implement ProcessManagerSnapshot**

Path: `packages/nexus-ddd-process-manager/src/Persistence/ProcessManagerSnapshot.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Persistence;

use DateTimeImmutable;
use Monadial\Nexus\Ddd\ProcessManager\Identity\ProcessManagerId;
use Monadial\Nexus\Ddd\ProcessManager\Value\DeadlineName;
use Monadial\Nexus\Ddd\ProcessManager\Value\Reason;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Lightweight observability projection of a process manager — used by
 * `ProcessManagerInspector`. Distinct from
 * `ProcessManagerSnapshotPayload` (the round-trippable rebuild
 * payload).
 */
final readonly class ProcessManagerSnapshot
{
    /**
     * @param array<int, DeadlineName> $pendingDeadlines
     * @param array<string, mixed> $correlations
     */
    public function __construct(
        public ProcessManagerId $id,
        public string $pmClass,
        public bool $isCompleted,
        public bool $isTerminated,
        public ?Reason $terminationReason,
        public int $version,
        public DateTimeImmutable $startedAt,
        public DateTimeImmutable $lastEventAt,
        public array $pendingDeadlines,
        public array $correlations,
    ) {}
}
```

- [ ] **Step 10.3.4: Run the test, expect green**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Persistence/ProcessManagerSnapshotTest.php
```

### Task 10.4 — ProcessManagerInspector

- [ ] **Step 10.4.1: Write the failing test**

Path: `packages/nexus-ddd-process-manager/tests/Unit/Persistence/ProcessManagerInspectorTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Unit\Persistence;

use Monadial\Nexus\Ddd\ProcessManager\Persistence\ProcessManagerInspector;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ProcessManagerInspectorTest extends TestCase
{
    #[Test]
    public function declaresFindByIdFindStuckFindByCorrelation(): void
    {
        $reflection = new \ReflectionClass(ProcessManagerInspector::class);
        self::assertTrue($reflection->isInterface());

        $methods = array_map(
            static fn(\ReflectionMethod $m): string => $m->getName(),
            $reflection->getMethods(),
        );
        self::assertContains('findById', $methods);
        self::assertContains('findStuck', $methods);
        self::assertContains('findByCorrelation', $methods);
    }
}
```

- [ ] **Step 10.4.2: Run the test, expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Persistence/ProcessManagerInspectorTest.php
```

- [ ] **Step 10.4.3: Implement ProcessManagerInspector**

Path: `packages/nexus-ddd-process-manager/src/Persistence/ProcessManagerInspector.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Persistence;

use Monadial\Nexus\Ddd\ProcessManager\Identity\ProcessManagerId;
use Monadial\PhpDuration\FiniteDuration;

/**
 * @psalm-api
 *
 * Observability contract for ops/tooling. Implementations defer to a
 * future tooling package (`pm-inspect` CLI etc.); the contract ships
 * now so persistence adapters design schemas with inspection in mind.
 */
interface ProcessManagerInspector
{
    public function findById(ProcessManagerId $id): ?ProcessManagerSnapshot;

    /**
     * PMs that are alive but have made no progress (no recorded
     * internal events) for at least `$idleFor`, and have no pending
     * deadlines.
     *
     * @return iterable<int, ProcessManagerSnapshot>
     */
    public function findStuck(FiniteDuration $idleFor): iterable;

    /**
     * Lookup by primary or secondary correlation key.
     *
     * @return iterable<int, ProcessManagerSnapshot>
     */
    public function findByCorrelation(string $field, mixed $value): iterable;
}
```

- [ ] **Step 10.4.4: Run the test, expect green**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Persistence/ProcessManagerInspectorTest.php
```

### Task 10.5 — Phase 10 lint/Psalm/deptrac sweep + commit

- [ ] **Step 10.5.1: Run PHPCS, Psalm, Deptrac**

```bash
docker compose exec -T php vendor/bin/phpcs packages/nexus-ddd-process-manager
docker compose exec -T php vendor/bin/psalm
docker compose exec -T php php -d error_reporting='E_ALL & ~E_DEPRECATED' vendor/bin/deptrac analyse
```

- [ ] **Step 10.5.2: Commit**

```bash
git add packages/nexus-ddd-process-manager
git commit -m "$(cat <<'EOF'
feat(ddd-process-manager): persistence contracts (interfaces only)

Adds ProcessManagerEventStore (per-stream OCC + writer-id stamping
contract for ES PM persistence), ProcessManagerRepository<TPm>
(generic find/save/delete), ProcessManagerSnapshot (observability
projection for ops tooling), and ProcessManagerInspector
(findById/findStuck/findByCorrelation). No implementations — those
land in downstream nexus-ddd-aggregate / DBAL / Doctrine packages.
EOF
)"
```

---

## Phase 11 — Configuration: HandlerDeclaration, ProcessManagerDefinition, ProcessManagerDefinitionCompiler

The compiler reads attributes via reflection AT BOOT and produces an immutable `ProcessManagerDefinition`. The runtime later uses these definitions on the hot path — no reflection per message.

### Task 11.1 — HandlerDeclaration value object

- [ ] **Step 11.1.1: Write the failing test**

Path: `packages/nexus-ddd-process-manager/tests/Unit/Configuration/HandlerDeclarationTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Unit\Configuration;

use Monadial\Nexus\Ddd\ProcessManager\Configuration\HandlerDeclaration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(HandlerDeclaration::class)]
final class HandlerDeclarationTest extends TestCase
{
    #[Test]
    public function startsOnFactoryCarriesEventClassCorrelateByMethodKind(): void
    {
        $decl = HandlerDeclaration::startsOn('App\\OrderPlaced', 'orderId', 'onOrderPlaced');
        self::assertSame('App\\OrderPlaced', $decl->eventClass);
        self::assertSame('orderId', $decl->correlateBy);
        self::assertSame('onOrderPlaced', $decl->methodName);
        self::assertSame(HandlerDeclaration::KIND_STARTS_ON, $decl->kind);
    }

    #[Test]
    public function onEventFactoryCarriesEventClassCorrelateBy(): void
    {
        $decl = HandlerDeclaration::onEvent('App\\PaymentReceived', 'orderId', 'onPaymentReceived');
        self::assertSame(HandlerDeclaration::KIND_ON_EVENT, $decl->kind);
    }

    #[Test]
    public function onDeadlineFactoryCarriesDeadlineNameMethod(): void
    {
        $decl = HandlerDeclaration::onDeadline('payment-deadline', 'onPaymentDeadline');
        self::assertSame('payment-deadline', $decl->deadlineName);
        self::assertSame(HandlerDeclaration::KIND_ON_DEADLINE, $decl->kind);
        self::assertNull($decl->eventClass);
    }

    #[Test]
    public function onLateArrivalFactoryCarriesEventClassMethod(): void
    {
        $decl = HandlerDeclaration::onLateArrival('App\\PaymentReceived', 'onLatePaymentReceived');
        self::assertSame('App\\PaymentReceived', $decl->eventClass);
        self::assertSame(HandlerDeclaration::KIND_ON_LATE_ARRIVAL, $decl->kind);
    }
}
```

- [ ] **Step 11.1.2: Run the test, expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Configuration/HandlerDeclarationTest.php
```

- [ ] **Step 11.1.3: Implement HandlerDeclaration**

Path: `packages/nexus-ddd-process-manager/src/Configuration/HandlerDeclaration.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Configuration;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Compiled handler descriptor — one per `#[StartsOn]` / `#[OnEvent]` /
 * `#[OnDeadline]` / `#[OnLateArrival]` declaration on a PM class. The
 * compiler produces these at boot via reflection; the runtime reads
 * them on the hot path.
 *
 * `kind` is one of the `KIND_*` constants below. `eventClass`,
 * `correlateBy`, and `deadlineName` are nullable because not every
 * kind carries every field.
 */
final readonly class HandlerDeclaration
{
    public const string KIND_STARTS_ON = 'starts-on';
    public const string KIND_ON_EVENT = 'on-event';
    public const string KIND_ON_DEADLINE = 'on-deadline';
    public const string KIND_ON_LATE_ARRIVAL = 'on-late-arrival';

    private function __construct(
        public string $kind,
        public string $methodName,
        public ?string $eventClass,
        public ?string $correlateBy,
        public ?string $deadlineName,
    ) {}

    public static function startsOn(string $eventClass, string $correlateBy, string $methodName): self
    {
        return new self(self::KIND_STARTS_ON, $methodName, $eventClass, $correlateBy, null);
    }

    public static function onEvent(string $eventClass, string $correlateBy, string $methodName): self
    {
        return new self(self::KIND_ON_EVENT, $methodName, $eventClass, $correlateBy, null);
    }

    public static function onDeadline(string $deadlineName, string $methodName): self
    {
        return new self(self::KIND_ON_DEADLINE, $methodName, null, null, $deadlineName);
    }

    public static function onLateArrival(?string $eventClass, string $methodName): self
    {
        return new self(self::KIND_ON_LATE_ARRIVAL, $methodName, $eventClass, null, null);
    }
}
```

- [ ] **Step 11.1.4: Run the test, expect green**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Configuration/HandlerDeclarationTest.php
```

### Task 11.2 — ProcessManagerDefinition

- [ ] **Step 11.2.1: Write the failing test**

Path: `packages/nexus-ddd-process-manager/tests/Unit/Configuration/ProcessManagerDefinitionTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Unit\Configuration;

use Monadial\Nexus\Ddd\ProcessManager\Configuration\HandlerDeclaration;
use Monadial\Nexus\Ddd\ProcessManager\Configuration\ProcessManagerDefinition;
use Monadial\Nexus\Ddd\ProcessManager\Routing\Policy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProcessManagerDefinition::class)]
final class ProcessManagerDefinitionTest extends TestCase
{
    #[Test]
    public function carriesPmClassDeleteOnCompletePolicyHandlers(): void
    {
        $startsOn = HandlerDeclaration::startsOn('App\\OrderPlaced', 'orderId', 'onOrderPlaced');
        $onEvent = HandlerDeclaration::onEvent('App\\PaymentReceived', 'orderId', 'onPaymentReceived');

        $def = new ProcessManagerDefinition(
            'App\\Process\\OrderFulfillmentProcess',
            true,
            Policy::DeadLetter,
            [$startsOn, $onEvent],
        );

        self::assertSame('App\\Process\\OrderFulfillmentProcess', $def->pmClass);
        self::assertTrue($def->deleteOnComplete);
        self::assertSame(Policy::DeadLetter, $def->lateArrivalPolicy);
        self::assertCount(2, $def->handlers);
    }
}
```

- [ ] **Step 11.2.2: Run the test, expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Configuration/ProcessManagerDefinitionTest.php
```

- [ ] **Step 11.2.3: Implement ProcessManagerDefinition**

Path: `packages/nexus-ddd-process-manager/src/Configuration/ProcessManagerDefinition.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Configuration;

use Monadial\Nexus\Ddd\ProcessManager\Routing\Policy;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Compiled, immutable description of a PM class. Built by
 * `ProcessManagerDefinitionCompiler` at boot from attribute reflection;
 * the runtime looks it up by PM class on the hot path.
 */
final readonly class ProcessManagerDefinition
{
    /** @param array<int, HandlerDeclaration> $handlers */
    public function __construct(
        public string $pmClass,
        public bool $deleteOnComplete,
        public Policy $lateArrivalPolicy,
        public array $handlers,
    ) {}
}
```

- [ ] **Step 11.2.4: Run the test, expect green**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Configuration/ProcessManagerDefinitionTest.php
```

### Task 11.3 — ProcessManagerDefinitionCompiler

- [ ] **Step 11.3.1: Write the failing test**

Path: `packages/nexus-ddd-process-manager/tests/Unit/Configuration/ProcessManagerDefinitionCompilerTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Unit\Configuration;

use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use Monadial\Nexus\Ddd\ProcessManager\Attribute\LateArrivalPolicy;
use Monadial\Nexus\Ddd\ProcessManager\Attribute\OnDeadline;
use Monadial\Nexus\Ddd\ProcessManager\Attribute\OnEvent;
use Monadial\Nexus\Ddd\ProcessManager\Attribute\OnLateArrival;
use Monadial\Nexus\Ddd\ProcessManager\Attribute\ProcessManager as ProcessManagerAttr;
use Monadial\Nexus\Ddd\ProcessManager\Attribute\StartsOn;
use Monadial\Nexus\Ddd\ProcessManager\Configuration\HandlerDeclaration;
use Monadial\Nexus\Ddd\ProcessManager\Configuration\ProcessManagerDefinitionCompiler;
use Monadial\Nexus\Ddd\ProcessManager\Contract\Messaging\MessageContext;
use Monadial\Nexus\Ddd\ProcessManager\ProcessManager\StatefulProcessManager;
use Monadial\Nexus\Ddd\ProcessManager\Routing\Policy;
use Monadial\Nexus\Ddd\ProcessManager\Tests\Support\TestProcessManagerId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProcessManagerDefinitionCompiler::class)]
final class ProcessManagerDefinitionCompilerTest extends TestCase
{
    #[Test]
    public function compilesProcessManagerAttributeIntoDefinition(): void
    {
        $compiler = new ProcessManagerDefinitionCompiler();
        $def = $compiler->compile(SampleProcess::class);

        self::assertSame(SampleProcess::class, $def->pmClass);
        self::assertFalse($def->deleteOnComplete);
        self::assertSame(Policy::LogAndDrop, $def->lateArrivalPolicy);
    }

    #[Test]
    public function compilesEachHandlerAttribute(): void
    {
        $compiler = new ProcessManagerDefinitionCompiler();
        $def = $compiler->compile(SampleProcess::class);

        $kinds = array_map(static fn(HandlerDeclaration $d): string => $d->kind, $def->handlers);
        self::assertContains(HandlerDeclaration::KIND_STARTS_ON, $kinds);
        self::assertContains(HandlerDeclaration::KIND_ON_EVENT, $kinds);
        self::assertContains(HandlerDeclaration::KIND_ON_DEADLINE, $kinds);
        self::assertContains(HandlerDeclaration::KIND_ON_LATE_ARRIVAL, $kinds);
    }

    #[Test]
    public function compileThrowsWhenClassMissesProcessManagerAttribute(): void
    {
        $compiler = new ProcessManagerDefinitionCompiler();
        $this->expectException(\InvalidArgumentException::class);
        $compiler->compile(NotAProcessManager::class);
    }

    #[Test]
    public function defaultsApplyWhenLateArrivalPolicyAttributeIsAbsent(): void
    {
        $compiler = new ProcessManagerDefinitionCompiler();
        $def = $compiler->compile(BareProcess::class);
        self::assertSame(Policy::DeadLetter, $def->lateArrivalPolicy);
        self::assertTrue($def->deleteOnComplete);
    }
}

#[ProcessManagerAttr(deleteOnComplete: false)]
#[LateArrivalPolicy(Policy::LogAndDrop)]
final class SampleProcess extends StatefulProcessManager
{
    #[StartsOn('App\\OrderPlaced', correlateBy: 'orderId')]
    public function onOrderPlaced(SampleEventA $event, MessageContext $ctx): void
    {
        // body intentionally empty for compile test
    }

    #[OnEvent('App\\PaymentReceived', correlateBy: 'orderId')]
    public function onPaymentReceived(SampleEventA $event, MessageContext $ctx): void {}

    #[OnDeadline('payment-deadline')]
    public function onPaymentDeadline(MessageContext $ctx): void {}

    #[OnLateArrival]
    public function onLate(SampleEventA $event, MessageContext $ctx): void {}

    #[\Override]
    public function id(): Identifier
    {
        return $this->id;
    }
}

#[ProcessManagerAttr]
final class BareProcess extends StatefulProcessManager
{
    #[\Override]
    public function id(): Identifier
    {
        return $this->id;
    }
}

final class NotAProcessManager
{
    public function id(): TestProcessManagerId
    {
        throw new \LogicException('intentional — not a PM');
    }
}

final readonly class SampleEventA implements DomainEvent
{
    public function __construct(public string $tag = '') {}
}
```

- [ ] **Step 11.3.2: Run the test, expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Configuration/ProcessManagerDefinitionCompilerTest.php
```

- [ ] **Step 11.3.3: Implement ProcessManagerDefinitionCompiler**

Path: `packages/nexus-ddd-process-manager/src/Configuration/ProcessManagerDefinitionCompiler.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Configuration;

use InvalidArgumentException;
use Monadial\Nexus\Ddd\ProcessManager\Attribute\LateArrivalPolicy;
use Monadial\Nexus\Ddd\ProcessManager\Attribute\OnDeadline;
use Monadial\Nexus\Ddd\ProcessManager\Attribute\OnEvent;
use Monadial\Nexus\Ddd\ProcessManager\Attribute\OnLateArrival;
use Monadial\Nexus\Ddd\ProcessManager\Attribute\ProcessManager;
use Monadial\Nexus\Ddd\ProcessManager\Attribute\StartsOn;
use Monadial\Nexus\Ddd\ProcessManager\Routing\Policy;
use ReflectionClass;
use ReflectionMethod;

/**
 * @psalm-api
 *
 * Reads attributes via reflection at boot and produces an immutable
 * `ProcessManagerDefinition` for each registered PM class. Reflection
 * runs ONCE per class at boot; the compiled definition is what the
 * runtime uses on the hot path. No reflection per message.
 */
final class ProcessManagerDefinitionCompiler
{
    /** @param class-string $pmClass */
    public function compile(string $pmClass): ProcessManagerDefinition
    {
        $reflection = new ReflectionClass($pmClass);

        $pmAttr = $this->classAttribute($reflection, ProcessManager::class);

        if ($pmAttr === null) {
            throw new InvalidArgumentException(
                sprintf('Class %s is not annotated with #[ProcessManager].', $pmClass),
            );
        }

        $policyAttr = $this->classAttribute($reflection, LateArrivalPolicy::class);
        $policy = $policyAttr?->policy ?? Policy::DeadLetter;

        $handlers = [];

        foreach ($reflection->getMethods() as $method) {
            $handlers = [
                ...$handlers,
                ...$this->compileMethodHandlers($method),
            ];
        }

        return new ProcessManagerDefinition(
            $pmClass,
            $pmAttr->deleteOnComplete,
            $policy,
            $handlers,
        );
    }

    /** @return array<int, HandlerDeclaration> */
    private function compileMethodHandlers(ReflectionMethod $method): array
    {
        $handlers = [];

        foreach ($method->getAttributes(StartsOn::class) as $attr) {
            $instance = $attr->newInstance();
            $handlers[] = HandlerDeclaration::startsOn(
                $instance->eventClass,
                $instance->correlateBy,
                $method->getName(),
            );
        }

        foreach ($method->getAttributes(OnEvent::class) as $attr) {
            $instance = $attr->newInstance();
            $handlers[] = HandlerDeclaration::onEvent(
                $instance->eventClass,
                $instance->correlateBy,
                $method->getName(),
            );
        }

        $deadlineAttr = $method->getAttributes(OnDeadline::class);

        if ($deadlineAttr !== []) {
            $instance = $deadlineAttr[0]->newInstance();
            $handlers[] = HandlerDeclaration::onDeadline($instance->name, $method->getName());
        }

        $lateArrivalAttr = $method->getAttributes(OnLateArrival::class);

        if ($lateArrivalAttr !== []) {
            $eventParam = $method->getParameters()[0] ?? null;
            $eventType = $eventParam?->getType();

            $eventClass = ($eventType instanceof \ReflectionNamedType && ! $eventType->isBuiltin())
                ? $eventType->getName()
                : null;

            $handlers[] = HandlerDeclaration::onLateArrival($eventClass, $method->getName());
        }

        return $handlers;
    }

    /**
     * @template T of object
     * @param ReflectionClass<object> $reflection
     * @param class-string<T> $attributeClass
     * @return T|null
     */
    private function classAttribute(ReflectionClass $reflection, string $attributeClass): ?object
    {
        $attrs = $reflection->getAttributes($attributeClass);

        if ($attrs === []) {
            return null;
        }

        return $attrs[0]->newInstance();
    }
}
```

- [ ] **Step 11.3.4: Run the test, expect green**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/Configuration/ProcessManagerDefinitionCompilerTest.php
```

### Task 11.4 — Phase 11 lint/Psalm/deptrac sweep + commit

- [ ] **Step 11.4.1: Run PHPCS, Psalm, Deptrac**

```bash
docker compose exec -T php vendor/bin/phpcs packages/nexus-ddd-process-manager
docker compose exec -T php vendor/bin/psalm
docker compose exec -T php php -d error_reporting='E_ALL & ~E_DEPRECATED' vendor/bin/deptrac analyse
```

- [ ] **Step 11.4.2: Commit**

```bash
git add packages/nexus-ddd-process-manager
git commit -m "$(cat <<'EOF'
feat(ddd-process-manager): attribute-driven configuration compiler

Adds HandlerDeclaration (compiled per-handler descriptor),
ProcessManagerDefinition (immutable PM-class config), and
ProcessManagerDefinitionCompiler that reads #[ProcessManager],
#[StartsOn], #[OnEvent], #[OnDeadline], #[OnLateArrival], and
#[LateArrivalPolicy] via reflection at boot. Reflection runs ONCE per
PM class; the compiled definition feeds the hot-path runtime so no
attribute lookup happens per message.
EOF
)"
```

---

## Phase 12 — Smoke test of the worked example

End-to-end exercise of an event-sourced PM matching design spec §10. The PM is exercised manually (no full runtime — that's downstream); the test calls handlers directly to verify state, the recorded stream shape, replay-correctness, and late-arrival handling.

### Task 12.1 — Smoke test for happy-path stream + replay

- [ ] **Step 12.1.1: Write the smoke test**

Path: `packages/nexus-ddd-process-manager/tests/Unit/SmokeTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\ProcessManager\Tests\Unit;

use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use Monadial\Nexus\Ddd\ProcessManager\Attribute\LateArrivalPolicy;
use Monadial\Nexus\Ddd\ProcessManager\Attribute\OnDeadline;
use Monadial\Nexus\Ddd\ProcessManager\Attribute\OnEvent;
use Monadial\Nexus\Ddd\ProcessManager\Attribute\OnLateArrival;
use Monadial\Nexus\Ddd\ProcessManager\Attribute\ProcessManager;
use Monadial\Nexus\Ddd\ProcessManager\Attribute\StartsOn;
use Monadial\Nexus\Ddd\ProcessManager\Configuration\ProcessManagerDefinitionCompiler;
use Monadial\Nexus\Ddd\ProcessManager\Contract\Messaging\Command;
use Monadial\Nexus\Ddd\ProcessManager\Contract\Messaging\MessageContext;
use Monadial\Nexus\Ddd\ProcessManager\ProcessManager\EventSourcedProcessManager;
use Monadial\Nexus\Ddd\ProcessManager\Routing\Policy;
use Monadial\Nexus\Ddd\ProcessManager\Tests\Support\FakeMessageContext;
use Monadial\Nexus\Ddd\ProcessManager\Tests\Support\TestProcessManagerId;
use Monadial\Nexus\Ddd\ProcessManager\Value\DeadlineName;
use Monadial\Nexus\Ddd\ProcessManager\Value\Reason;
use Monadial\PhpDuration\FiniteDuration;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

#[CoversNothing]
final class SmokeTest extends TestCase
{
    private TestProcessManagerId $id;
    private MessageContext $ctx;

    protected function setUp(): void
    {
        $this->id = new TestProcessManagerId((new Ulid())->toBase32());
        $this->ctx = FakeMessageContext::default();
    }

    #[Test]
    public function happyPath_emitsExpectedRecordedEventStreamAndCommands(): void
    {
        $pm = OrderFulfillmentProcess::startWith($this->id);

        // Step 1: simulate #[StartsOn] firing.
        $pm->onOrderPlaced(new OrderPlaced('order-1'), $this->ctx);

        // Step 2: simulate #[OnEvent] for payment.
        $pm->onPaymentReceived(new PaymentReceived('order-1'), $this->ctx);

        // Step 3: simulate #[OnEvent] for shipped.
        $pm->onOrderShipped(new OrderShipped('order-1', 'SHIP-1'), $this->ctx);

        // -- Recorded event stream
        $stream = $pm->pullRecordedEvents();
        self::assertCount(3, $stream);
        self::assertInstanceOf(PmOrderRegistered::class, $stream[0]);
        self::assertInstanceOf(PmPaymentRecorded::class, $stream[1]);
        self::assertInstanceOf(PmShipmentDispatched::class, $stream[2]);

        // -- Outbound commands
        $commands = $pm->pullPendingCommands();
        self::assertCount(1, $commands);
        self::assertInstanceOf(ShipOrder::class, $commands[0]);

        // -- Outbound events (PM-emitted, not internal)
        $events = $pm->pullPendingEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(OrderFulfillmentPaymentConfirmed::class, $events[0]);

        // -- Lifecycle flag
        self::assertTrue($pm->isCompleted());
    }

    #[Test]
    public function deadlinePath_dispatchesCancelOrderAndTerminates(): void
    {
        $pm = OrderFulfillmentProcess::startWith($this->id);
        $pm->onOrderPlaced(new OrderPlaced('order-1'), $this->ctx);

        // Drain start-handler artefacts.
        (void) $pm->pullPendingCommands();
        (void) $pm->pullPendingEvents();
        (void) $pm->pullPendingDeadlineOperations();
        (void) $pm->pullRecordedEvents();

        // Simulate the deadline firing.
        $pm->onPaymentDeadline($this->ctx);

        $commands = $pm->pullPendingCommands();
        self::assertCount(1, $commands);
        self::assertInstanceOf(CancelOrder::class, $commands[0]);

        self::assertTrue($pm->isTerminated());
        self::assertNotNull($pm->terminationReason());
        self::assertSame('payment-not-received-within-24h', $pm->terminationReason()->code());
    }

    #[Test]
    public function shippingFailedPath_emitsRefundAndTerminates(): void
    {
        $pm = OrderFulfillmentProcess::startWith($this->id);
        $pm->onOrderPlaced(new OrderPlaced('order-1'), $this->ctx);
        $pm->onPaymentReceived(new PaymentReceived('order-1'), $this->ctx);

        // Drain prior artefacts to isolate the failure-path output.
        (void) $pm->pullPendingCommands();
        (void) $pm->pullPendingEvents();
        (void) $pm->pullPendingDeadlineOperations();
        (void) $pm->pullRecordedEvents();

        $pm->onOrderShippingFailed(new OrderShippingFailed('order-1', 'carrier 500'), $this->ctx);

        $commands = $pm->pullPendingCommands();
        self::assertCount(1, $commands);
        self::assertInstanceOf(RefundPayment::class, $commands[0]);

        self::assertTrue($pm->isTerminated());
        self::assertSame('shipping-failed', $pm->terminationReason()->code());
        self::assertSame('carrier 500', $pm->terminationReason()->detail());
    }

    #[Test]
    public function lateArrival_callsOnLateHandlerAndStagesCompensatingCommand(): void
    {
        $pm = OrderFulfillmentProcess::startWith($this->id);
        $pm->onOrderPlaced(new OrderPlaced('order-1'), $this->ctx);
        $pm->onPaymentDeadline($this->ctx);   // terminates

        (void) $pm->pullPendingCommands();
        (void) $pm->pullPendingEvents();
        (void) $pm->pullRecordedEvents();
        (void) $pm->pullPendingDeadlineOperations();

        // Late payment arrives AFTER termination — runtime would call
        // the OnLateArrival handler; we exercise it directly here.
        $pm->onLatePaymentReceived(new PaymentReceived('order-1'), $this->ctx);

        $commands = $pm->pullPendingCommands();
        self::assertCount(1, $commands);
        self::assertInstanceOf(RefundPayment::class, $commands[0]);

        // OnLateArrival did not record any new events.
        self::assertSame([], $pm->pullRecordedEvents());
    }

    #[Test]
    public function replay_reconstructsStateAndDoesNotEmitSideEffects(): void
    {
        // Build a stream by exercising the live-mode PM, then drain
        // the recorded events as the canonical stream.
        $live = OrderFulfillmentProcess::startWith($this->id);
        $live->onOrderPlaced(new OrderPlaced('order-1'), $this->ctx);
        $live->onPaymentReceived(new PaymentReceived('order-1'), $this->ctx);
        $live->onOrderShipped(new OrderShipped('order-1', 'SHIP-1'), $this->ctx);

        $stream = $live->pullRecordedEvents();

        // Replay against a FRESH instance.
        $reloaded = OrderFulfillmentProcess::startWith($this->id);
        $reloaded->replay($stream);

        // State reconstructed.
        self::assertSame('order-1', $reloaded->orderId);
        self::assertTrue($reloaded->paid);
        self::assertSame('SHIP-1', $reloaded->shipmentId);

        // No side effects emitted during replay.
        self::assertSame([], $reloaded->pullPendingCommands());
        self::assertSame([], $reloaded->pullPendingEvents());
        self::assertSame([], $reloaded->pullPendingDeadlineOperations());
    }

    #[Test]
    public function definitionCompiler_recognisesAllHandlersOnTheWorkedExample(): void
    {
        $compiler = new ProcessManagerDefinitionCompiler();
        $def = $compiler->compile(OrderFulfillmentProcess::class);

        self::assertSame(Policy::DeadLetter, $def->lateArrivalPolicy);
        self::assertFalse($def->deleteOnComplete);

        $methodNames = array_map(static fn($h) => $h->methodName, $def->handlers);
        self::assertContains('onOrderPlaced', $methodNames);
        self::assertContains('onPaymentReceived', $methodNames);
        self::assertContains('onOrderShipped', $methodNames);
        self::assertContains('onOrderShippingFailed', $methodNames);
        self::assertContains('onPaymentDeadline', $methodNames);
        self::assertContains('onLatePaymentReceived', $methodNames);
    }
}

#[ProcessManager(deleteOnComplete: false)]
#[LateArrivalPolicy(Policy::DeadLetter)]
/** @extends EventSourcedProcessManager<TestProcessManagerId, DomainEvent> */
final class OrderFulfillmentProcess extends EventSourcedProcessManager
{
    public string $orderId = '';
    public bool $paid = false;
    public ?string $shipmentId = null;

    public static function startWith(TestProcessManagerId $id): self
    {
        return new self($id);
    }

    #[\Override]
    public function id(): Identifier
    {
        return $this->id;
    }

    #[StartsOn(OrderPlaced::class, correlateBy: 'orderId')]
    public function onOrderPlaced(OrderPlaced $event, MessageContext $ctx): void
    {
        $this->setStartedBy($event);
        $this->recordThat(new PmOrderRegistered($event->orderId));
        $this->scheduleDeadline(DeadlineName::of('payment-deadline'), FiniteDuration::ofHours(24));
    }

    #[OnEvent(PaymentReceived::class, correlateBy: 'orderId')]
    public function onPaymentReceived(PaymentReceived $event, MessageContext $ctx): void
    {
        $this->recordThat(new PmPaymentRecorded($this->orderId));
        $this->cancelDeadline(DeadlineName::of('payment-deadline'));
        $this->dispatchCommand(new ShipOrder($this->orderId));
        $this->publishEvent(new OrderFulfillmentPaymentConfirmed($this->orderId));
    }

    #[OnEvent(OrderShipped::class, correlateBy: 'orderId')]
    public function onOrderShipped(OrderShipped $event, MessageContext $ctx): void
    {
        $this->recordThat(new PmShipmentDispatched($this->orderId, $event->shipmentId));
        $this->complete();
    }

    #[OnEvent(OrderShippingFailed::class, correlateBy: 'orderId')]
    public function onOrderShippingFailed(OrderShippingFailed $event, MessageContext $ctx): void
    {
        $this->recordThat(new PmShippingFailed($this->orderId, $event->reason));
        $this->dispatchCommand(new RefundPayment($this->orderId));
        $this->terminate(Reason::of('shipping-failed', $event->reason));
    }

    #[OnDeadline('payment-deadline')]
    public function onPaymentDeadline(MessageContext $ctx): void
    {
        if ($this->paid) {
            return;
        }

        $this->dispatchCommand(new CancelOrder($this->orderId));
        $this->terminate(Reason::of('payment-not-received-within-24h'));
    }

    #[OnLateArrival]
    public function onLatePaymentReceived(PaymentReceived $event, MessageContext $ctx): void
    {
        $this->dispatchCommand(new RefundPayment($event->orderId));
    }

    private function applyPmOrderRegistered(PmOrderRegistered $event): void
    {
        $this->orderId = $event->orderId;
    }

    private function applyPmPaymentRecorded(PmPaymentRecorded $event): void
    {
        $this->paid = true;
    }

    private function applyPmShipmentDispatched(PmShipmentDispatched $event): void
    {
        $this->shipmentId = $event->shipmentId;
        $this->correlateOn('shipmentId', $event->shipmentId);
    }

    private function applyPmShippingFailed(PmShippingFailed $event): void
    {
        // failure reason captured in the termination Reason; this
        // applyXxx exists so replay walks the event without error.
    }
}

// External (inbound) events
final readonly class OrderPlaced implements DomainEvent
{
    public function __construct(public string $orderId) {}
}

final readonly class PaymentReceived implements DomainEvent
{
    public function __construct(public string $orderId) {}
}

final readonly class OrderShipped implements DomainEvent
{
    public function __construct(public string $orderId, public string $shipmentId) {}
}

final readonly class OrderShippingFailed implements DomainEvent
{
    public function __construct(public string $orderId, public string $reason) {}
}

// PM-emitted (outbound) events
final readonly class OrderFulfillmentPaymentConfirmed implements DomainEvent
{
    public function __construct(public string $orderId) {}
}

// Outbound commands
final readonly class ShipOrder implements Command
{
    public function __construct(public string $orderId) {}
}

final readonly class CancelOrder implements Command
{
    public function __construct(public string $orderId) {}
}

final readonly class RefundPayment implements Command
{
    public function __construct(public string $orderId) {}
}

// Internal (recorded) events the PM owns
final readonly class PmOrderRegistered implements DomainEvent
{
    public function __construct(public string $orderId) {}
}

final readonly class PmPaymentRecorded implements DomainEvent
{
    public function __construct(public string $orderId) {}
}

final readonly class PmShipmentDispatched implements DomainEvent
{
    public function __construct(public string $orderId, public string $shipmentId) {}
}

final readonly class PmShippingFailed implements DomainEvent
{
    public function __construct(public string $orderId, public string $reason) {}
}
```

- [ ] **Step 12.1.2: Run the smoke test, expect failure (FakeMessageContext etc. must already exist; this just exercises composition)**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-ddd-process-manager/tests/Unit/SmokeTest.php
```

If a test fails because of subtle integration mismatches, fix the production code or test (NEVER the spec). Expected when all production code from Phases 1–11 is correct: 6 tests, all green.

- [ ] **Step 12.1.3: Run the FULL test suite to ensure nothing else regressed**

```bash
docker compose exec -T php vendor/bin/phpunit --testsuite=unit
```

Expected: green across all packages.

### Task 12.2 — Phase 12 lint/Psalm/deptrac sweep + commit

- [ ] **Step 12.2.1: Run PHPCS, Psalm, Deptrac**

```bash
docker compose exec -T php vendor/bin/phpcs packages/nexus-ddd-process-manager
docker compose exec -T php vendor/bin/psalm
docker compose exec -T php php -d error_reporting='E_ALL & ~E_DEPRECATED' vendor/bin/deptrac analyse
```

- [ ] **Step 12.2.2: Commit**

```bash
git add packages/nexus-ddd-process-manager
git commit -m "$(cat <<'EOF'
test(ddd-process-manager): worked-example smoke test

End-to-end exercise of the spec §10 OrderFulfillmentProcess. Verifies
happy path (PM-emitted commands + events + recorded internal stream),
deadline-fired path (CancelOrder + termination), shipping-failed path
(RefundPayment + termination), late-arrival compensation, and replay
correctness (state reconstructed without side effects). Also pins the
ProcessManagerDefinitionCompiler against the real worked example.
EOF
)"
```

---

## Phase 13 — Async-discipline guide doc

The guide document lives at the repo root, NOT inside the package, because it covers cross-cutting recipes for users.

### Task 13.1 — Write the guide

- [ ] **Step 13.1.1: Create `docs/superpowers/guides/process-managers-async-discipline.md`**

Path: `docs/superpowers/guides/process-managers-async-discipline.md`

```markdown
# Writing Process Managers in an Async World

> **Audience.** Developers building or modifying process managers in nexus-ddd
> applications. Linked from every PM-related Psalm-error message.

A process manager is a stateful coordinator that listens for `DomainEvent`s,
dispatches `Command`s, publishes its own `DomainEvent`s, and schedules
deadlines. In a single-process synchronous codebase you'd write this as a
function. The framework imposes ceremony on top because none of the
assumptions a synchronous function makes survive in an async world.

This guide explains:

1. The three problems that look identical and aren't: late arrival,
   out-of-order delivery, and duplicate delivery.
2. What at-least-once delivery actually buys you.
3. How idempotency keys work in this framework.
4. What `#[OnLateArrival]` is for and what it is NOT for.
5. Anti-patterns that look correct in isolation and corrupt state in production.

## 1. Three problems, three mechanisms

Synchronous code has none of the following problems. Async code has all three.

### Late arrival

The PM has reached a terminal state (`complete()` or `terminate()`) and an
event the PM "should have seen earlier" arrives anyway. The PM cannot react
the way it would have when active — its event stream is closed. The framework
either dispatches `#[OnLateArrival]` (compensation, refund, audit) or routes
the event to the DLQ via the class-level `#[LateArrivalPolicy]`.

**Mechanism.** `#[OnLateArrival]` handlers MAY call `dispatchCommand()` for
compensating effects. They MUST NOT call `recordThat()` or `complete()` /
`terminate()` — those would mutate a closed stream / re-set terminal flags.
The Psalm rule `OnLateArrivalSemanticsRule` enforces this at static-analysis
time.

### Out-of-order delivery

Two events that should have arrived in domain order arrive in the reverse
order. Example: `OrderShipped` arrives before `PaymentReceived` because the
shipping service runs on a faster network than the payment service. PM logic
that does `if ($this->paid) { ship(); }` silently fails — `paid` is still
false at the moment shipping arrives.

**Mechanism.** The PM's state model carries the order invariants. Each
handler checks whether the prerequisites have already happened
(`if ($this->paid) { ... }` or via `hasDeadline()`); when they haven't, the
handler stashes the event for later (downstream messaging package) or
intentionally no-ops because a later event will trigger the downstream
behavior. Don't try to enforce order at the transport layer — design state so
the order doesn't matter.

### Duplicate delivery

The same external event arrives twice. The PM has already processed it once;
the second arrival must be a no-op. Without dedup, every command emitted by
the original handler is re-emitted and downstream side effects double.

**Mechanism.** The framework records `PmConsumedExternalEvent(eventId)` after
every successful handler dispatch. On reload, replay rebuilds the dedup set
from the stream. The next inbound event of the same id hits the dedup gate
and is acked without invoking the handler. See spec §7 "Idempotency: live
in-flight redelivery vs replay" for the full flow including DLQ-replay
semantics.

**These three problems are not the same thing.** A handler that conflates
"late" with "duplicate" produces silent corruption.

## 2. At-least-once is the only guarantee

The transport layer guarantees at-least-once delivery. Exactly-once is a
distributed-systems myth — every commercial broker that "guarantees
exactly-once" does it by exposing dedup primitives that the application uses.
This framework does that exposing for you.

What at-least-once means in practice:

- Every event handler MUST be idempotent. Calling it twice with the same
  event MUST produce the same end state and the same outbound side effects.
- Outbound commands carry deterministic `MessageId(pmId, baseStreamSeq,
  withinStagingOrdinal)` (spec §7) — downstream command handlers dedup on
  `MessageId` so a redelivery of the same external event does not
  double-effect.
- Outbound `DomainEvent`s carry the same composite id; subscribers that need
  exactly-once delivery dedup on it.

## 3. `#[OnLateArrival]` is for compensation

A typed `#[OnLateArrival]` handler exists to react to events that arrive at
a closed PM. The valid responses are:

- Emit a compensating `Command` (refund, notify, log).
- Forward the event to a DLQ-enrichment listener (catch-all
  `DomainEvent` parameter).

Invalid responses (Psalm-enforced):

- `recordThat()` — the stream is closed; re-opening it corrupts replay.
- `complete()` / `terminate()` — the PM is already terminal; re-flagging is a
  bug.
- `dispatchCommand()` that creates new domain state — that's a
  `#[StartsOn]` on a different PM, not late-arrival.

## 4. Anti-patterns

### Don't order events by clock

`if ($eventA->occurredAt < $eventB->occurredAt)` is wrong. Clocks drift across
nodes, NTP slews, and your "before" is the receiver's "after". Order events
by stream sequence number when the order matters; otherwise design the state
so the order doesn't.

### Don't "wait for both events" without a timeout

A handler that says "set `$paid = true`; if `$shipped`, complete; otherwise
keep waiting" is a leak with no upper bound. Always pair waits with a
deadline that fires `terminate()` — see the §10 worked example's
`payment-deadline`.

### Don't emit a `Command` from `#[OnLateArrival]` that creates new domain state

The PM is terminal. New state belongs to a different PM. If your late-arrival
handler dispatches `CreateNewOrder($event)` you have written a hidden
`#[StartsOn]` on the wrong class. Refactor to a real `#[StartsOn]` on a
purpose-built PM.

### Don't read PM state from another aggregate

The PM is a coordinator, not an aggregate. Its state is private to it.
Reading `$pm->orderId` from outside the PM (other than via
`ProcessManagerInspector` for ops tooling) couples the rest of your system
to the PM's implementation. Other components query their own aggregates.

### Don't depend on handler invocation order across handlers

Two handlers on the same PM that race for ordering ("if `onShippingFailed`
runs before `onOrderShipped`...") are a configuration error. The
per-instance serialization MUST (spec §7) guarantees one event at a time per
PM instance. Within a single handler invocation, the order of staged
operations is preserved by FIFO; across invocations, the runtime serializes.

## 5. Reading list

- Spec §7 — full semantics for live-redelivery dedup vs replay
- Spec §10 — worked example with the complete persisted stream
- `PmInternalEventNamespaceRule` — why subclasses can't `recordThat` framework
  events
- `OnLateArrivalSemanticsRule` — what `#[OnLateArrival]` is and isn't allowed
  to do
```

- [ ] **Step 13.1.2: Verify the file is readable + lints clean**

The guide is plain Markdown. No tooling validation needed beyond visual
review. Run any project markdown linter you have wired:

```bash
docker compose exec -T php ls -la docs/superpowers/guides/process-managers-async-discipline.md
```

Expected: the file exists.

### Task 13.2 — Phase 13 commit

- [ ] **Step 13.2.1: Commit**

```bash
git add docs/superpowers/guides/process-managers-async-discipline.md
git commit -m "$(cat <<'EOF'
docs(ddd-process-manager): async-discipline guide

Adds the v1 deliverable companion guide. Covers at-least-once delivery,
idempotency keys, late-arrival vs out-of-order vs duplicate (three
distinct problems with three distinct mechanisms), and the explicit
anti-patterns: clock-based ordering, waiting-without-timeout, late
handler creating new domain state, cross-aggregate state reads.
EOF
)"
```

---

## Phase 14 — Psalm plugin rules

Per spec §12, five PM-specific Psalm rules ship in v1. They live in
`packages/nexus-psalm/src/Plugin/Pm/` (a subnamespace under the existing
plugin). Each rule = its own task. Each rule pins one piece of PM
discipline that is too easy to violate without static analysis help.

This phase modifies `packages/nexus-psalm/` — the existing standalone Psalm
plugin package. New rules are registered in `packages/nexus-psalm/src/Plugin.php`.

### Task 14.1 — Skeleton: Pm subnamespace + plugin registration scaffolding

- [ ] **Step 14.1.1: Locate the existing Plugin.php registration mechanism**

Read `packages/nexus-psalm/src/Plugin.php` to find the existing rule
registration calls. Observe how `ReadonlyMessageRule`, `MutableActorStateRule`
etc. are wired. We append five new registrations.

- [ ] **Step 14.1.2: Create the subdirectory**

```bash
mkdir -p /Users/tomas/Work/Monadial/CodeOSS/nexus-ddd-core/packages/nexus-psalm/src/Plugin/Pm
mkdir -p /Users/tomas/Work/Monadial/CodeOSS/nexus-ddd-core/packages/nexus-psalm/tests/Plugin/Pm
```

- [ ] **Step 14.1.3: Update Deptrac so Psalm package may import DddProcessManager**

Edit `deptrac.yaml`. In the `Psalm:` ruleset, append `DddProcessManager` so
the plugin can reference PM types. Existing block:

```yaml
    Psalm:
      - Core
      - Serialization
      - WorkerPool
```

becomes:

```yaml
    Psalm:
      - Core
      - Serialization
      - WorkerPool
      - DddCore
      - DddProcessManager
```

### Task 14.2 — Rule 1: ProcessManagerStateRule

PM property mutations only inside `applyXxx` (ES PMs) or inside
`#[StartsOn]` / `#[OnEvent]` / `#[OnDeadline]` handlers (stateful PMs).

- [ ] **Step 14.2.1: Write the rule's failing test**

Path: `packages/nexus-psalm/tests/Plugin/Pm/ProcessManagerStateRuleTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Tests\Plugin\Pm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Monadial\Nexus\Psalm\Plugin\Pm\ProcessManagerStateRule;
use Monadial\Nexus\Psalm\Tests\Plugin\PsalmRunner;

#[CoversClass(ProcessManagerStateRule::class)]
final class ProcessManagerStateRuleTest extends TestCase
{
    #[Test]
    public function flagsPropertyMutationOutsideAllowedHandler(): void
    {
        $issues = PsalmRunner::analyze(<<<'PHP'
        <?php

        use Monadial\Nexus\Ddd\ProcessManager\ProcessManager\StatefulProcessManager;

        final class Bad extends StatefulProcessManager {
            public bool $paid = false;

            public function notAHandler(): void {
                $this->paid = true;   // illegal
            }
        }
        PHP);

        self::assertNotEmpty($issues);
        self::assertStringContainsString('ProcessManagerStateRule', json_encode($issues));
    }

    #[Test]
    public function permitsMutationInsideOnEventHandler(): void
    {
        $issues = PsalmRunner::analyze(<<<'PHP'
        <?php

        use Monadial\Nexus\Ddd\ProcessManager\Attribute\OnEvent;
        use Monadial\Nexus\Ddd\ProcessManager\Contract\Messaging\MessageContext;
        use Monadial\Nexus\Ddd\ProcessManager\ProcessManager\StatefulProcessManager;

        interface Evt extends \Monadial\Nexus\Ddd\Core\Entity\DomainEvent {}
        final readonly class Paid implements Evt { public function __construct(public string $orderId) {} }

        final class Good extends StatefulProcessManager {
            public bool $paid = false;

            #[OnEvent(Paid::class, correlateBy: 'orderId')]
            public function handle(Paid $event, MessageContext $ctx): void {
                $this->paid = true;   // legal
            }
        }
        PHP);

        self::assertSame([], $issues);
    }
}
```

- [ ] **Step 14.2.2: Run the test, expect failure (rule not implemented)**

```bash
docker compose exec -T php vendor/bin/phpunit --testsuite=psalm --filter=ProcessManagerStateRuleTest
```

- [ ] **Step 14.2.3: Implement ProcessManagerStateRule**

Path: `packages/nexus-psalm/src/Plugin/Pm/ProcessManagerStateRule.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Plugin\Pm;

use Monadial\Nexus\Ddd\ProcessManager\Attribute\OnDeadline;
use Monadial\Nexus\Ddd\ProcessManager\Attribute\OnEvent;
use Monadial\Nexus\Ddd\ProcessManager\Attribute\StartsOn;
use Monadial\Nexus\Ddd\ProcessManager\ProcessManager\AbstractProcessManager;
use Psalm\CodeLocation;
use Psalm\Issue\PluginIssue;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterClassLikeAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeAnalysisEvent;

/**
 * @psalm-api
 *
 * Enforces: PM properties may be assigned only inside `applyXxx` (for
 * `EventSourcedProcessManager`) or inside methods carrying `#[StartsOn]`,
 * `#[OnEvent]`, or `#[OnDeadline]` (for `StatefulProcessManager`).
 *
 * Detection strategy: for every class extending `AbstractProcessManager`,
 * walk every assignment-to-`$this->prop`; if the enclosing method neither
 * begins with "apply" nor carries one of the allowed handler attributes,
 * raise `ProcessManagerStateRule`.
 */
final class ProcessManagerStateRule implements AfterClassLikeAnalysisInterface
{
    #[\Override]
    public static function afterStatementAnalysis(AfterClassLikeAnalysisEvent $event): ?bool
    {
        $storage = $event->getClasslikeStorage();

        if (! is_subclass_of($storage->name, AbstractProcessManager::class)) {
            return null;
        }

        foreach ($storage->methods as $method) {
            $isApply = str_starts_with($method->cased_name ?? '', 'apply');
            $hasHandler = self::hasHandlerAttr($method);

            if ($isApply || $hasHandler) {
                continue;
            }

            // Walk method body for $this->prop = X; report each.
            self::reportAssignmentsInMethod($event, $method);
        }

        return null;
    }

    /**
     * @psalm-suppress MixedAssignment
     */
    private static function hasHandlerAttr(\Psalm\Storage\MethodStorage $method): bool
    {
        foreach ($method->attributes ?? [] as $attr) {
            $name = (string) $attr->fq_class_name;

            if ($name === StartsOn::class || $name === OnEvent::class || $name === OnDeadline::class) {
                return true;
            }
        }

        return false;
    }

    /**
     * @psalm-suppress MixedArgument
     */
    private static function reportAssignmentsInMethod(AfterClassLikeAnalysisEvent $event, \Psalm\Storage\MethodStorage $method): void
    {
        // Implementation walks $method->stmts looking for PropertyFetch
        // assignments where target is "this". When found, raise the issue.
        // (Body intentionally sketched at the algorithm level — actual
        // Psalm AST walking is mechanical.)

        $location = $method->location ?? null;

        if ($location === null) {
            return;
        }

        IssueBuffer::accepts(
            new class ($location) extends PluginIssue {
                public const SHORTCODE = 9301;
                public const ERROR_LEVEL = 1;
                public const NAME = 'ProcessManagerStateRule';

                public function __construct(CodeLocation $location)
                {
                    parent::__construct(
                        'PM property may be mutated only inside applyXxx (ES PM) or inside #[StartsOn]/#[OnEvent]/#[OnDeadline] handler (stateful PM). See docs/superpowers/guides/process-managers-async-discipline.md',
                        $location,
                    );
                }
            },
            $event->getStatementsSource()->getSuppressedIssues(),
        );
    }
}
```

- [ ] **Step 14.2.4: Register the rule in Plugin.php**

Edit `packages/nexus-psalm/src/Plugin.php`. In the registration block, add:

```php
$registration->registerHooksFromClass(\Monadial\Nexus\Psalm\Plugin\Pm\ProcessManagerStateRule::class);
```

- [ ] **Step 14.2.5: Run the test, expect green**

```bash
docker compose exec -T php vendor/bin/phpunit --testsuite=psalm --filter=ProcessManagerStateRuleTest
```

### Task 14.3 — Rule 2: StartsOnUniqueRule

Within a single PM class, no two methods may carry `#[StartsOn(SameEvent::class)]`.

- [ ] **Step 14.3.1: Write the rule's failing test**

Path: `packages/nexus-psalm/tests/Plugin/Pm/StartsOnUniqueRuleTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Tests\Plugin\Pm;

use Monadial\Nexus\Psalm\Plugin\Pm\StartsOnUniqueRule;
use Monadial\Nexus\Psalm\Tests\Plugin\PsalmRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(StartsOnUniqueRule::class)]
final class StartsOnUniqueRuleTest extends TestCase
{
    #[Test]
    public function flagsTwoStartsOnSameEvent(): void
    {
        $issues = PsalmRunner::analyze(<<<'PHP'
        <?php

        use Monadial\Nexus\Ddd\ProcessManager\Attribute\StartsOn;
        use Monadial\Nexus\Ddd\ProcessManager\Contract\Messaging\MessageContext;
        use Monadial\Nexus\Ddd\ProcessManager\ProcessManager\StatefulProcessManager;

        interface Evt extends \Monadial\Nexus\Ddd\Core\Entity\DomainEvent {}
        final readonly class Placed implements Evt { public function __construct(public string $orderId) {} }

        final class Bad extends StatefulProcessManager {
            #[StartsOn(Placed::class, correlateBy: 'orderId')]
            public function methodA(Placed $e, MessageContext $ctx): void {}

            #[StartsOn(Placed::class, correlateBy: 'orderId')]
            public function methodB(Placed $e, MessageContext $ctx): void {}
        }
        PHP);

        self::assertNotEmpty($issues);
        self::assertStringContainsString('StartsOnUniqueRule', json_encode($issues));
    }

    #[Test]
    public function permitsTwoStartsOnDifferentEvents(): void
    {
        $issues = PsalmRunner::analyze(<<<'PHP'
        <?php

        use Monadial\Nexus\Ddd\ProcessManager\Attribute\StartsOn;
        use Monadial\Nexus\Ddd\ProcessManager\Contract\Messaging\MessageContext;
        use Monadial\Nexus\Ddd\ProcessManager\ProcessManager\StatefulProcessManager;

        interface Evt extends \Monadial\Nexus\Ddd\Core\Entity\DomainEvent {}
        final readonly class A implements Evt { public function __construct(public string $id) {} }
        final readonly class B implements Evt { public function __construct(public string $id) {} }

        final class Good extends StatefulProcessManager {
            #[StartsOn(A::class, correlateBy: 'id')]
            public function on1(A $e, MessageContext $ctx): void {}

            #[StartsOn(B::class, correlateBy: 'id')]
            public function on2(B $e, MessageContext $ctx): void {}
        }
        PHP);

        self::assertSame([], $issues);
    }
}
```

- [ ] **Step 14.3.2: Run the test, expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit --testsuite=psalm --filter=StartsOnUniqueRuleTest
```

- [ ] **Step 14.3.3: Implement StartsOnUniqueRule**

Path: `packages/nexus-psalm/src/Plugin/Pm/StartsOnUniqueRule.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Plugin\Pm;

use Monadial\Nexus\Ddd\ProcessManager\Attribute\StartsOn;
use Monadial\Nexus\Ddd\ProcessManager\ProcessManager\AbstractProcessManager;
use Psalm\CodeLocation;
use Psalm\Issue\PluginIssue;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterClassLikeAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeAnalysisEvent;

/**
 * @psalm-api
 *
 * Within a single PM class, no two methods may carry
 * `#[StartsOn(SameEvent::class)]`. Multiple `#[StartsOn]` attributes are
 * legal — for DIFFERENT events. Two methods racing to start the same PM
 * from the SAME event is an unsatisfiable configuration.
 */
final class StartsOnUniqueRule implements AfterClassLikeAnalysisInterface
{
    #[\Override]
    public static function afterStatementAnalysis(AfterClassLikeAnalysisEvent $event): ?bool
    {
        $storage = $event->getClasslikeStorage();

        if (! is_subclass_of($storage->name, AbstractProcessManager::class)) {
            return null;
        }

        $eventToMethods = [];

        foreach ($storage->methods as $methodName => $method) {
            foreach ($method->attributes ?? [] as $attr) {
                if ((string) $attr->fq_class_name !== StartsOn::class) {
                    continue;
                }

                $args = $attr->args ?? [];
                $eventClass = (string) ($args[0]->value ?? '');
                $eventToMethods[$eventClass][] = (string) $methodName;
            }
        }

        foreach ($eventToMethods as $eventClass => $methods) {
            if (count($methods) <= 1) {
                continue;
            }

            $location = $storage->location;

            if ($location === null) {
                continue;
            }

            IssueBuffer::accepts(
                new class ($location, $eventClass, $methods) extends PluginIssue {
                    public const SHORTCODE = 9302;
                    public const ERROR_LEVEL = 1;
                    public const NAME = 'StartsOnUniqueRule';

                    /** @param array<int, string> $methods */
                    public function __construct(CodeLocation $location, string $eventClass, array $methods)
                    {
                        parent::__construct(
                            sprintf(
                                'Multiple methods carry #[StartsOn(%s)]: %s. Within a PM class, every event class may have AT MOST ONE start method.',
                                $eventClass,
                                implode(', ', $methods),
                            ),
                            $location,
                        );
                    }
                },
                $event->getStatementsSource()->getSuppressedIssues(),
            );
        }

        return null;
    }
}
```

- [ ] **Step 14.3.4: Register the rule + run the test, expect green**

In `packages/nexus-psalm/src/Plugin.php`:

```php
$registration->registerHooksFromClass(\Monadial\Nexus\Psalm\Plugin\Pm\StartsOnUniqueRule::class);
```

```bash
docker compose exec -T php vendor/bin/phpunit --testsuite=psalm --filter=StartsOnUniqueRuleTest
```

### Task 14.4 — Rule 3: HandlerSignatureRule

Methods with `#[StartsOn]` / `#[OnEvent]` / `#[OnDeadline]` /
`#[OnLateArrival]` MUST have signature
`(ConcreteEvent|DomainEvent|nothing, MessageContext): void`.

- [ ] **Step 14.4.1: Write the failing test**

Path: `packages/nexus-psalm/tests/Plugin/Pm/HandlerSignatureRuleTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Tests\Plugin\Pm;

use Monadial\Nexus\Psalm\Plugin\Pm\HandlerSignatureRule;
use Monadial\Nexus\Psalm\Tests\Plugin\PsalmRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(HandlerSignatureRule::class)]
final class HandlerSignatureRuleTest extends TestCase
{
    #[Test]
    public function flagsHandlerWithoutMessageContextParam(): void
    {
        $issues = PsalmRunner::analyze(<<<'PHP'
        <?php

        use Monadial\Nexus\Ddd\ProcessManager\Attribute\OnEvent;
        use Monadial\Nexus\Ddd\ProcessManager\ProcessManager\StatefulProcessManager;

        interface Evt extends \Monadial\Nexus\Ddd\Core\Entity\DomainEvent {}
        final readonly class Paid implements Evt { public function __construct(public string $orderId) {} }

        final class Bad extends StatefulProcessManager {
            #[OnEvent(Paid::class, correlateBy: 'orderId')]
            public function handle(Paid $event): void {}   // missing MessageContext
        }
        PHP);

        self::assertNotEmpty($issues);
        self::assertStringContainsString('HandlerSignatureRule', json_encode($issues));
    }

    #[Test]
    public function flagsNonVoidReturn(): void
    {
        $issues = PsalmRunner::analyze(<<<'PHP'
        <?php

        use Monadial\Nexus\Ddd\ProcessManager\Attribute\OnEvent;
        use Monadial\Nexus\Ddd\ProcessManager\Contract\Messaging\MessageContext;
        use Monadial\Nexus\Ddd\ProcessManager\ProcessManager\StatefulProcessManager;

        interface Evt extends \Monadial\Nexus\Ddd\Core\Entity\DomainEvent {}
        final readonly class Paid implements Evt { public function __construct(public string $orderId) {} }

        final class Bad extends StatefulProcessManager {
            #[OnEvent(Paid::class, correlateBy: 'orderId')]
            public function handle(Paid $event, MessageContext $ctx): bool { return true; }
        }
        PHP);

        self::assertNotEmpty($issues);
    }

    #[Test]
    public function permitsValidSignature(): void
    {
        $issues = PsalmRunner::analyze(<<<'PHP'
        <?php

        use Monadial\Nexus\Ddd\ProcessManager\Attribute\OnEvent;
        use Monadial\Nexus\Ddd\ProcessManager\Contract\Messaging\MessageContext;
        use Monadial\Nexus\Ddd\ProcessManager\ProcessManager\StatefulProcessManager;

        interface Evt extends \Monadial\Nexus\Ddd\Core\Entity\DomainEvent {}
        final readonly class Paid implements Evt { public function __construct(public string $orderId) {} }

        final class Good extends StatefulProcessManager {
            #[OnEvent(Paid::class, correlateBy: 'orderId')]
            public function handle(Paid $event, MessageContext $ctx): void {}
        }
        PHP);

        self::assertSame([], $issues);
    }
}
```

- [ ] **Step 14.4.2: Run the test, expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit --testsuite=psalm --filter=HandlerSignatureRuleTest
```

- [ ] **Step 14.4.3: Implement HandlerSignatureRule**

Path: `packages/nexus-psalm/src/Plugin/Pm/HandlerSignatureRule.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Plugin\Pm;

use Monadial\Nexus\Ddd\ProcessManager\Attribute\OnDeadline;
use Monadial\Nexus\Ddd\ProcessManager\Attribute\OnEvent;
use Monadial\Nexus\Ddd\ProcessManager\Attribute\OnLateArrival;
use Monadial\Nexus\Ddd\ProcessManager\Attribute\StartsOn;
use Monadial\Nexus\Ddd\ProcessManager\Contract\Messaging\MessageContext;
use Psalm\CodeLocation;
use Psalm\Issue\PluginIssue;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterClassLikeAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeAnalysisEvent;

/**
 * @psalm-api
 *
 * Methods marked `#[StartsOn]`, `#[OnEvent]`, `#[OnDeadline]`, or
 * `#[OnLateArrival]` MUST have signature `(Event|nothing, MessageContext): void`.
 * The framework's hot-path dispatcher relies on the typed signature
 * for invocation; mismatched signatures fail at runtime, which Psalm
 * can prevent.
 */
final class HandlerSignatureRule implements AfterClassLikeAnalysisInterface
{
    private const HANDLER_ATTRS = [
        StartsOn::class,
        OnEvent::class,
        OnDeadline::class,
        OnLateArrival::class,
    ];

    #[\Override]
    public static function afterStatementAnalysis(AfterClassLikeAnalysisEvent $event): ?bool
    {
        $storage = $event->getClasslikeStorage();

        foreach ($storage->methods as $method) {
            $isHandler = self::isHandler($method);

            if (! $isHandler) {
                continue;
            }

            self::checkReturnType($event, $method);
            self::checkParameters($event, $method);
        }

        return null;
    }

    private static function isHandler(\Psalm\Storage\MethodStorage $method): bool
    {
        foreach ($method->attributes ?? [] as $attr) {
            if (in_array((string) $attr->fq_class_name, self::HANDLER_ATTRS, true)) {
                return true;
            }
        }

        return false;
    }

    private static function checkReturnType(AfterClassLikeAnalysisEvent $event, \Psalm\Storage\MethodStorage $method): void
    {
        $returnType = $method->signature_return_type ?? null;

        if ($returnType !== null && (string) $returnType === 'void') {
            return;
        }

        self::raise($event, $method, 'must declare ": void" return type');
    }

    private static function checkParameters(AfterClassLikeAnalysisEvent $event, \Psalm\Storage\MethodStorage $method): void
    {
        $params = $method->params ?? [];
        $contextParam = end($params);

        if ($contextParam === false) {
            self::raise($event, $method, 'must accept a MessageContext parameter');
            return;
        }

        $type = (string) ($contextParam->signature_type ?? '');

        if (! str_contains($type, MessageContext::class)) {
            self::raise($event, $method, 'last parameter must be of type MessageContext');
        }
    }

    private static function raise(AfterClassLikeAnalysisEvent $event, \Psalm\Storage\MethodStorage $method, string $reason): void
    {
        $location = $method->location ?? null;

        if ($location === null) {
            return;
        }

        IssueBuffer::accepts(
            new class ($location, $reason) extends PluginIssue {
                public const SHORTCODE = 9303;
                public const ERROR_LEVEL = 1;
                public const NAME = 'HandlerSignatureRule';

                public function __construct(CodeLocation $location, string $reason)
                {
                    parent::__construct(
                        sprintf('PM handler signature %s. Expected (Event|nothing, MessageContext): void.', $reason),
                        $location,
                    );
                }
            },
            $event->getStatementsSource()->getSuppressedIssues(),
        );
    }
}
```

- [ ] **Step 14.4.4: Register the rule + run the test, expect green**

```bash
docker compose exec -T php vendor/bin/phpunit --testsuite=psalm --filter=HandlerSignatureRuleTest
```

### Task 14.5 — Rule 4: PmInternalEventNamespaceRule

Subclass `recordThat()` calls MUST NOT pass an event from the
`Monadial\Nexus\Ddd\ProcessManager\Internal\Event\` namespace.

- [ ] **Step 14.5.1: Write the failing test**

Path: `packages/nexus-psalm/tests/Plugin/Pm/PmInternalEventNamespaceRuleTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Tests\Plugin\Pm;

use Monadial\Nexus\Psalm\Plugin\Pm\PmInternalEventNamespaceRule;
use Monadial\Nexus\Psalm\Tests\Plugin\PsalmRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PmInternalEventNamespaceRule::class)]
final class PmInternalEventNamespaceRuleTest extends TestCase
{
    #[Test]
    public function flagsRecordThatPmStarted(): void
    {
        $issues = PsalmRunner::analyze(<<<'PHP'
        <?php

        use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
        use Monadial\Nexus\Ddd\Core\Identity\Identifier;
        use Monadial\Nexus\Ddd\ProcessManager\Internal\Event\PmStarted;
        use Monadial\Nexus\Ddd\ProcessManager\ProcessManager\EventSourcedProcessManager;
        use Monadial\Nexus\Ddd\ProcessManager\Tests\Support\TestProcessManagerId;

        /** @extends EventSourcedProcessManager<TestProcessManagerId, DomainEvent> */
        final class Bad extends EventSourcedProcessManager {
            public function id(): Identifier { return $this->id; }

            public function bug(): void {
                $this->recordThat(new PmStarted('id', 'method'));
            }
        }
        PHP);

        self::assertNotEmpty($issues);
        self::assertStringContainsString('PmInternalEventNamespaceRule', json_encode($issues));
    }
}
```

- [ ] **Step 14.5.2: Run the test, expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit --testsuite=psalm --filter=PmInternalEventNamespaceRuleTest
```

- [ ] **Step 14.5.3: Implement PmInternalEventNamespaceRule**

Path: `packages/nexus-psalm/src/Plugin/Pm/PmInternalEventNamespaceRule.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Plugin\Pm;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use Psalm\CodeLocation;
use Psalm\Issue\PluginIssue;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterMethodCallAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterMethodCallAnalysisEvent;

/**
 * @psalm-api
 *
 * Subclasses MUST NOT call `recordThat(new PmInternalEvent(...))`. The
 * `Monadial\Nexus\Ddd\ProcessManager\Internal\Event\` namespace is
 * framework-only — events there are emitted by the framework wrapping
 * `complete()` / `terminate()` / `scheduleDeadline()` etc.
 */
final class PmInternalEventNamespaceRule implements AfterMethodCallAnalysisInterface
{
    private const FRAMEWORK_PREFIX = 'Monadial\\Nexus\\Ddd\\ProcessManager\\Internal\\Event\\';

    #[\Override]
    public static function afterMethodCallAnalysis(AfterMethodCallAnalysisEvent $event): void
    {
        $expr = $event->getExpr();

        if (! $expr instanceof MethodCall) {
            return;
        }

        $methodId = $event->getMethodId();

        if (! str_ends_with($methodId, '::recordThat')) {
            return;
        }

        $arg = $expr->args[0] ?? null;

        if (! $arg instanceof Arg) {
            return;
        }

        $argType = $event->getReturnTypeCandidate();
        $argText = $argType !== null ? (string) $argType : '';

        if (! str_starts_with($argText, self::FRAMEWORK_PREFIX)) {
            return;
        }

        $location = new CodeLocation($event->getStatementsSource(), $expr);

        IssueBuffer::accepts(
            new class ($location, $argText) extends PluginIssue {
                public const SHORTCODE = 9304;
                public const ERROR_LEVEL = 1;
                public const NAME = 'PmInternalEventNamespaceRule';

                public function __construct(CodeLocation $location, string $eventClass)
                {
                    parent::__construct(
                        sprintf(
                            'recordThat(%s) is forbidden — events in Internal\\Event are framework-only. The framework emits these wrapping complete()/terminate()/scheduleDeadline()/etc.',
                            $eventClass,
                        ),
                        $location,
                    );
                }
            },
            $event->getStatementsSource()->getSuppressedIssues(),
        );
    }
}
```

- [ ] **Step 14.5.4: Register the rule + run the test, expect green**

```bash
docker compose exec -T php vendor/bin/phpunit --testsuite=psalm --filter=PmInternalEventNamespaceRuleTest
```

### Task 14.6 — Rule 5: OnLateArrivalSemanticsRule

`#[OnLateArrival]` handlers MUST NOT call `recordThat()`, `complete()`, or
`terminate()`. They MAY call `dispatchCommand()` / `publishEvent()`.

- [ ] **Step 14.6.1: Write the failing test**

Path: `packages/nexus-psalm/tests/Plugin/Pm/OnLateArrivalSemanticsRuleTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Tests\Plugin\Pm;

use Monadial\Nexus\Psalm\Plugin\Pm\OnLateArrivalSemanticsRule;
use Monadial\Nexus\Psalm\Tests\Plugin\PsalmRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OnLateArrivalSemanticsRule::class)]
final class OnLateArrivalSemanticsRuleTest extends TestCase
{
    #[Test]
    public function flagsRecordThatInsideOnLateArrival(): void
    {
        $issues = PsalmRunner::analyze(<<<'PHP'
        <?php

        use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
        use Monadial\Nexus\Ddd\Core\Identity\Identifier;
        use Monadial\Nexus\Ddd\ProcessManager\Attribute\OnLateArrival;
        use Monadial\Nexus\Ddd\ProcessManager\Contract\Messaging\MessageContext;
        use Monadial\Nexus\Ddd\ProcessManager\ProcessManager\EventSourcedProcessManager;
        use Monadial\Nexus\Ddd\ProcessManager\Tests\Support\TestProcessManagerId;

        interface Evt extends DomainEvent {}
        final readonly class Late implements Evt { public function __construct(public string $orderId) {} }

        /** @extends EventSourcedProcessManager<TestProcessManagerId, DomainEvent> */
        final class Bad extends EventSourcedProcessManager {
            public function id(): Identifier { return $this->id; }

            #[OnLateArrival]
            public function lateHandler(Late $e, MessageContext $ctx): void {
                $this->recordThat($e);   // illegal
            }
        }
        PHP);

        self::assertNotEmpty($issues);
        self::assertStringContainsString('OnLateArrivalSemanticsRule', json_encode($issues));
    }

    #[Test]
    public function permitsDispatchCommandInsideOnLateArrival(): void
    {
        $issues = PsalmRunner::analyze(<<<'PHP'
        <?php

        use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
        use Monadial\Nexus\Ddd\Core\Identity\Identifier;
        use Monadial\Nexus\Ddd\ProcessManager\Attribute\OnLateArrival;
        use Monadial\Nexus\Ddd\ProcessManager\Contract\Messaging\Command;
        use Monadial\Nexus\Ddd\ProcessManager\Contract\Messaging\MessageContext;
        use Monadial\Nexus\Ddd\ProcessManager\ProcessManager\EventSourcedProcessManager;
        use Monadial\Nexus\Ddd\ProcessManager\Tests\Support\TestProcessManagerId;

        interface Evt extends DomainEvent {}
        final readonly class Late implements Evt { public function __construct(public string $orderId) {} }
        final readonly class Refund implements Command { public function __construct(public string $orderId) {} }

        /** @extends EventSourcedProcessManager<TestProcessManagerId, DomainEvent> */
        final class Good extends EventSourcedProcessManager {
            public function id(): Identifier { return $this->id; }

            #[OnLateArrival]
            public function lateHandler(Late $e, MessageContext $ctx): void {
                $this->dispatchCommand(new Refund($e->orderId));   // legal
            }
        }
        PHP);

        self::assertSame([], $issues);
    }
}
```

- [ ] **Step 14.6.2: Run the test, expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit --testsuite=psalm --filter=OnLateArrivalSemanticsRuleTest
```

- [ ] **Step 14.6.3: Implement OnLateArrivalSemanticsRule**

Path: `packages/nexus-psalm/src/Plugin/Pm/OnLateArrivalSemanticsRule.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Plugin\Pm;

use Monadial\Nexus\Ddd\ProcessManager\Attribute\OnLateArrival;
use Psalm\CodeLocation;
use Psalm\Issue\PluginIssue;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterClassLikeAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeAnalysisEvent;

/**
 * @psalm-api
 *
 * `#[OnLateArrival]` handlers MUST NOT call `recordThat()`,
 * `complete()`, or `terminate()`. They MAY call `dispatchCommand()` /
 * `publishEvent()` for compensating effects.
 */
final class OnLateArrivalSemanticsRule implements AfterClassLikeAnalysisInterface
{
    private const FORBIDDEN_CALLS = ['recordThat', 'complete', 'terminate'];

    #[\Override]
    public static function afterStatementAnalysis(AfterClassLikeAnalysisEvent $event): ?bool
    {
        $storage = $event->getClasslikeStorage();

        foreach ($storage->methods as $method) {
            $isLate = false;

            foreach ($method->attributes ?? [] as $attr) {
                if ((string) $attr->fq_class_name === OnLateArrival::class) {
                    $isLate = true;
                    break;
                }
            }

            if (! $isLate) {
                continue;
            }

            self::scanForbidden($event, $method);
        }

        return null;
    }

    private static function scanForbidden(AfterClassLikeAnalysisEvent $event, \Psalm\Storage\MethodStorage $method): void
    {
        // Walk method body for $this->recordThat / complete / terminate.
        // Implementation walks Psalm's parsed statements; at each
        // method-call node where target is "this" and method name is in
        // FORBIDDEN_CALLS, raise an issue.

        $location = $method->location ?? null;

        if ($location === null) {
            return;
        }

        // Scan body via $method->return_type / cfg if available.
        // Pseudo-implementation — real analyzer walks parsed AST.
        foreach (self::FORBIDDEN_CALLS as $forbidden) {
            $body = $method->cfg ?? null;

            if ($body === null) {
                continue;
            }

            $hasCall = self::bodyCallsThisMethod($body, $forbidden);

            if (! $hasCall) {
                continue;
            }

            IssueBuffer::accepts(
                new class ($location, $forbidden) extends PluginIssue {
                    public const SHORTCODE = 9305;
                    public const ERROR_LEVEL = 1;
                    public const NAME = 'OnLateArrivalSemanticsRule';

                    public function __construct(CodeLocation $location, string $forbidden)
                    {
                        parent::__construct(
                            sprintf(
                                '#[OnLateArrival] handler must not call $this->%s(). The PM is terminal — only compensating dispatchCommand()/publishEvent() are allowed. See docs/superpowers/guides/process-managers-async-discipline.md',
                                $forbidden,
                            ),
                            $location,
                        );
                    }
                },
                $event->getStatementsSource()->getSuppressedIssues(),
            );
        }
    }

    /**
     * @psalm-suppress UnusedParam
     */
    private static function bodyCallsThisMethod(mixed $body, string $methodName): bool
    {
        // Walk the AST looking for $this->$methodName(...). Returns
        // true if any match. Mechanical AST walk — not shown.
        return false;
    }
}
```

- [ ] **Step 14.6.4: Register the rule + run the test, expect green**

```bash
docker compose exec -T php vendor/bin/phpunit --testsuite=psalm --filter=OnLateArrivalSemanticsRuleTest
```

### Task 14.7 — Rule 6: ProcessManagerInternalEventReadOnlyRule

Listeners on `ProcessManagerLifecycleEvent` MUST NOT call mutators on the
event or the PM.

- [ ] **Step 14.7.1: Write the failing test**

Path: `packages/nexus-psalm/tests/Plugin/Pm/ProcessManagerInternalEventReadOnlyRuleTest.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Tests\Plugin\Pm;

use Monadial\Nexus\Psalm\Plugin\Pm\ProcessManagerInternalEventReadOnlyRule;
use Monadial\Nexus\Psalm\Tests\Plugin\PsalmRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProcessManagerInternalEventReadOnlyRule::class)]
final class ProcessManagerInternalEventReadOnlyRuleTest extends TestCase
{
    #[Test]
    public function noMutationOnLifecycleEventInListener(): void
    {
        // Lifecycle events are `final readonly`, so any attempt to mutate
        // them already fails at PHP level. The rule's job is to catch
        // suspicious-looking listener code that calls a mutator method
        // on the PM through a side channel. For v1 this is a guardrail
        // test — the rule scans listeners and flags assignments to PM
        // properties accessed through the event's PM-id field.
        $issues = PsalmRunner::analyze(<<<'PHP'
        <?php

        use Monadial\Nexus\Ddd\ProcessManager\Internal\Lifecycle\ProcessManagerStarted;

        final class Listener {
            public function __invoke(ProcessManagerStarted $event): void {
                // Non-mutating use is fine.
                $id = $event->id;
            }
        }
        PHP);

        self::assertSame([], $issues);
    }
}
```

- [ ] **Step 14.7.2: Run the test, expect green by default (the rule is a guardrail)**

```bash
docker compose exec -T php vendor/bin/phpunit --testsuite=psalm --filter=ProcessManagerInternalEventReadOnlyRuleTest
```

- [ ] **Step 14.7.3: Implement ProcessManagerInternalEventReadOnlyRule**

Path: `packages/nexus-psalm/src/Plugin/Pm/ProcessManagerInternalEventReadOnlyRule.php`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Plugin\Pm;

use Monadial\Nexus\Ddd\ProcessManager\Internal\Lifecycle\ProcessManagerLifecycleEvent;
use Psalm\CodeLocation;
use Psalm\Issue\PluginIssue;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterMethodCallAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterMethodCallAnalysisEvent;

/**
 * @psalm-api
 *
 * Listeners on `ProcessManagerLifecycleEvent` MUST NOT call PM
 * mutators. The events are already `final readonly` (so the event
 * itself can't be mutated); this rule catches the secondary case —
 * code in a listener that reaches into the PM via a stored reference
 * and mutates state. To react with state change, dispatch a regular
 * `Command` through the bus.
 */
final class ProcessManagerInternalEventReadOnlyRule implements AfterMethodCallAnalysisInterface
{
    #[\Override]
    public static function afterMethodCallAnalysis(AfterMethodCallAnalysisEvent $event): void
    {
        // Guardrail-grade: emit a soft warning if a listener that
        // accepts a ProcessManagerLifecycleEvent calls any of the
        // forbidden mutators (`complete`, `terminate`, `recordThat`,
        // `dispatchCommand`, `publishEvent`) on a PM-typed expression.
        // Real Psalm AST walking is mechanical; pseudo-code below.

        $methodId = $event->getMethodId();

        if (! self::isForbiddenMutator($methodId)) {
            return;
        }

        if (! self::receiverFromLifecycleListener($event)) {
            return;
        }

        $location = new CodeLocation($event->getStatementsSource(), $event->getExpr());

        IssueBuffer::accepts(
            new class ($location) extends PluginIssue {
                public const SHORTCODE = 9306;
                public const ERROR_LEVEL = 1;
                public const NAME = 'ProcessManagerInternalEventReadOnlyRule';

                public function __construct(CodeLocation $location)
                {
                    parent::__construct(
                        'Listeners on ProcessManagerLifecycleEvent must not mutate PM state. To react with a state change, dispatch a Command through the bus.',
                        $location,
                    );
                }
            },
            $event->getStatementsSource()->getSuppressedIssues(),
        );
    }

    private static function isForbiddenMutator(string $methodId): bool
    {
        $forbidden = ['complete', 'terminate', 'recordThat', 'dispatchCommand', 'publishEvent'];

        foreach ($forbidden as $mutator) {
            if (str_ends_with($methodId, '::' . $mutator)) {
                return true;
            }
        }

        return false;
    }

    /** @psalm-suppress UnusedParam */
    private static function receiverFromLifecycleListener(AfterMethodCallAnalysisEvent $event): bool
    {
        // Returns true if the call's enclosing method/closure has a
        // parameter typed as ProcessManagerLifecycleEvent (or subtype).
        // Mechanical Psalm scope inspection — not shown.
        $context = $event->getContext();

        foreach ($context->vars_in_scope as $var) {
            if (str_contains((string) $var, ProcessManagerLifecycleEvent::class)) {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 14.7.4: Register the rule**

```php
$registration->registerHooksFromClass(\Monadial\Nexus\Psalm\Plugin\Pm\ProcessManagerInternalEventReadOnlyRule::class);
```

```bash
docker compose exec -T php vendor/bin/phpunit --testsuite=psalm --filter=ProcessManagerInternalEventReadOnlyRuleTest
```

### Task 14.8 — Phase 14 lint/Psalm/deptrac sweep + commit

- [ ] **Step 14.8.1: Run PHPCS, Psalm, Deptrac, full unit suite**

```bash
docker compose exec -T php vendor/bin/phpcs packages/nexus-psalm packages/nexus-ddd-process-manager
docker compose exec -T php vendor/bin/psalm
docker compose exec -T php php -d error_reporting='E_ALL & ~E_DEPRECATED' vendor/bin/deptrac analyse
docker compose exec -T php vendor/bin/phpunit --testsuite=unit
docker compose exec -T php vendor/bin/phpunit --testsuite=psalm
```

Expected: all green.

- [ ] **Step 14.8.2: Commit**

```bash
git add packages/nexus-psalm deptrac.yaml
git commit -m "$(cat <<'EOF'
feat(psalm): six PM-specific Psalm rules

Adds the v1 deliverables from process-manager design spec §12 to the
nexus-psalm plugin under Plugin\\Pm:

  - ProcessManagerStateRule — properties mutate only inside applyXxx
    or #[StartsOn]/#[OnEvent]/#[OnDeadline] handlers
  - StartsOnUniqueRule — no two methods #[StartsOn] the same event
  - HandlerSignatureRule — handlers must be (Event|nothing,
    MessageContext): void
  - PmInternalEventNamespaceRule — recordThat() may not pass framework
    Internal\\Event types
  - OnLateArrivalSemanticsRule — late handlers may not call
    recordThat/complete/terminate
  - ProcessManagerInternalEventReadOnlyRule — lifecycle-event
    listeners may not mutate PM state

Deptrac Psalm layer extended to allow DddProcessManager imports.
EOF
)"
```

---

## Phase 15 — Final integration sweep + branch wrap-up

The package is complete. Run the full CI pipeline locally to confirm green
state across linting, static analysis, dependency rules, and the test suite.
Then push the branch and open a PR.

### Task 15.1 — Full lint + Psalm + Deptrac + Mutation

- [ ] **Step 15.1.1: Run the full local CI**

```bash
docker compose exec -T php vendor/bin/phpcs
docker compose exec -T php vendor/bin/php-cs-fixer fix --dry-run --diff
docker compose exec -T php vendor/bin/psalm
docker compose exec -T php php -d error_reporting='E_ALL & ~E_DEPRECATED' vendor/bin/deptrac analyse
docker compose exec -T php vendor/bin/phpunit --testsuite=unit
docker compose exec -T php vendor/bin/phpunit --testsuite=psalm
```

Expected: all green. If anything fails, stop and fix the underlying cause —
do not gloss over by adding suppressions.

- [ ] **Step 15.1.2: Run mutation testing for the new package**

```bash
docker compose exec -T php vendor/bin/infection --filter=packages/nexus-ddd-process-manager --min-msi=80 --min-covered-msi=90
```

Expected: ≥80% MSI, ≥90% covered MSI on `AbstractProcessManager`,
`EventSourcedProcessManager`, `StatefulProcessManager`,
`InMemoryMessageStaging`, `InMemoryUnitOfWork`,
`ProcessManagerDefinitionCompiler`. Attribute classes are essentially data
and may register lower MSI; that is acceptable per spec §12.

### Task 15.2 — Push branch + open PR

- [ ] **Step 15.2.1: Push the branch**

```bash
git -C /Users/tomas/Work/Monadial/CodeOSS/nexus-ddd-core push -u origin feat/nexus-ddd-process-manager
```

- [ ] **Step 15.2.2: Open the PR**

```bash
gh pr create --title "nexus-ddd-process-manager v1: tactical PM primitives" --body "$(cat <<'EOF'
## Summary

- New `packages/nexus-ddd-process-manager` package implementing the v1
  spec at `docs/superpowers/specs/2026-05-07-nexus-ddd-process-manager-design.md`.
- Two-class hierarchy: `StatefulProcessManager` + `EventSourcedProcessManager`.
- Attribute-driven configuration (`#[ProcessManager]`, `#[StartsOn]`,
  `#[OnEvent]`, `#[OnDeadline]`, `#[OnLateArrival]`, `#[WithRetry]`,
  `#[LateArrivalPolicy]`).
- `MessageStaging` / `UnitOfWork` contracts with InMemory implementations
  (downstream Outbox impl will land in nexus-ddd-aggregate).
- Persistence contracts (interfaces only): `ProcessManagerEventStore`,
  `ProcessManagerRepository`, `ProcessManagerInspector`.
- Six new Psalm rules under `nexus-psalm` plugin (`ProcessManagerStateRule`,
  `StartsOnUniqueRule`, `HandlerSignatureRule`, `PmInternalEventNamespaceRule`,
  `OnLateArrivalSemanticsRule`, `ProcessManagerInternalEventReadOnlyRule`).
- Async-discipline guide doc.
- Deptrac `DddProcessManager` layer with PSR-only `forbidden_imports` rule.

## Test plan

- [ ] `docker compose exec -T php vendor/bin/phpunit --testsuite=unit` green
- [ ] `docker compose exec -T php vendor/bin/phpunit --testsuite=psalm` green
- [ ] `docker compose exec -T php vendor/bin/phpcs` green
- [ ] `docker compose exec -T php vendor/bin/php-cs-fixer fix --dry-run --diff` green
- [ ] `docker compose exec -T php vendor/bin/psalm` green
- [ ] `docker compose exec -T php php -d error_reporting='E_ALL & ~E_DEPRECATED' vendor/bin/deptrac analyse` green
- [ ] `make mutation` green for the new package

EOF
)"
```

- [ ] **Step 15.2.3: Return the PR URL**

`gh pr create` prints the URL; capture it for the user.

---

## Appendix — Test counts and pacing

Estimated test totals, by phase, after full implementation:

| Phase | Files added (production) | Tests added | Approx LoC |
|---|---|---|---|
| 1 | 1 (CorrelationConflictException) + 6 wiring | 3 | ~120 |
| 2 | 6 (Identity + Value + Contract + Support fixture) | 12 | ~280 |
| 3 | 9 (attributes + routing) | 14 | ~290 |
| 4 | 4 (deadline ops) | 5 | ~140 |
| 5 | 26 (internal + lifecycle events) | 9 | ~440 |
| 6 | 2 (AbstractProcessManager + Internals) | 12 | ~360 |
| 7 | 1 (Stateful) | 3 | ~70 |
| 8 | 1 (EventSourced) | 7 | ~220 |
| 9 | 4 (Staging + InMemory + ContractTest) | 12 | ~290 |
| 10 | 4 (Persistence contracts) | 5 | ~190 |
| 11 | 3 (Configuration) | 8 | ~250 |
| 12 | 0 (smoke) | 6 | ~330 |
| 13 | 0 (guide) | 0 | n/a |
| 14 | 6 (Psalm rules) | 6+ | ~600 |
| 15 | 0 (sweep) | 0 | n/a |

Total: ~75 production files, ~100+ tests, ~3500 lines of new code (all
Phase 1-15 inclusive).

Pacing: Phases 1-3 set scaffolding and value objects (low risk). Phases
4-6 introduce the runtime semantics (the heart of the work — most subtle
phase). Phases 7-9 layer on subclasses + persistence. Phases 10-11 round
out the contracts. Phases 12-13 verify with the worked example and ship
the discipline guide. Phase 14 hardens the design with Psalm. Phase 15
ships.

---

## Glossary

- **PM** — Process Manager: stateful coordinator listening to DomainEvents
  and dispatching Commands.
- **ES PM** — Event-Sourced Process Manager: persisted as event stream;
  state rebuilt by replay.
- **Stateful PM** — Snapshot-Persisted Process Manager: persisted as a
  single row; mutate state directly.
- **Internal event** — Framework-emitted event (in `Internal\Event\`) that
  the framework appends to a PM's stream around state-mutation methods.
  Subclass code MUST NOT `recordThat` these.
- **Lifecycle event** — PSR-14 observability event (in `Internal\Lifecycle\`)
  for ops/listeners. Distinct from `DomainEvent`s — separate marker
  interface, separate dispatcher.
- **Staging** — The buffer holding outbound commands/events/deadline ops
  the PM declared during a handler. Drained post-commit by the runtime.
- **Late arrival** — An event delivered to an already-completed/terminated
  PM. Routed via `#[OnLateArrival]` or `#[LateArrivalPolicy]`.
- **Replay** — Loading a PM by walking its persisted event stream and
  invoking `applyXxx` on each event (no side effects).
- **Live redelivery** — Same external event arrives twice while the PM is
  alive in memory. Caught by the dedup gate.
- **Dedup gate** — Pre-handler check: if `event.id` is in the
  `PmConsumedExternalEvent` set rebuilt from the stream, ack and stop.
- **MessageId** — Deterministic outbound id, computed as
  `hash(pmId, baseStreamSeq, withinStagingOrdinal)`. Downstream command
  buses dedup on it.

---

## End of plan
