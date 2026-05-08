# Nexus DDD Aggregate Persistence Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement the `nexus-ddd-aggregate` package — the persistence layer for the Nexus DDD framework. Repository + Strategy abstractions, in-memory event-sourcing strategy, in-memory stateful strategy, `VersionedEventStore` with UNIQUE-constraint OCC, stream strategies, the upcaster pipeline, snapshot integration with fall-back-to-replay semantics, and the Psalm rules that pin the new architectural invariants. **No DBAL/Doctrine impls in this package** — those live in `nexus-ddd-dbal` / `nexus-ddd-doctrine` (P1 follow-ups).

**Architecture:** `AggregateRepository<T>` is the public seam domain code sees — `find()` / `add()` / `save()`. Internally, it delegates to `PersistenceStrategy`, a thin composite over two persister interfaces split by aggregate kind: `EventSourcedPersister` (uses `VersionedEventStore` + `SnapshotStore` + `UpcasterPipeline` + `StreamStrategy`) and `StatefulPersister`. Brand-new aggregate creation (`add()` or `save()` of a v=0 aggregate) routes uniqueness collisions to `AggregateAlreadyExistsException` (terminal); load-then-save mid-air collisions route to `OptimisticLockException` (retried by middleware in the bus package). Apply-purity, factory-only-sets-id, and aggregates-emit-events-only invariants are enforced statically by Psalm rules in `nexus-psalm`. The package is in-memory-first; concrete DB impls inherit the contracts.

**Already shipped — re-used as-is, NOT redefined:**
- `Monadial\Nexus\Ddd\Core\Aggregate\AggregateRoot` — abstract base; tracks `version` + `recordedEvents`
- `Monadial\Nexus\Ddd\Core\Aggregate\EventSourcedAggregateRoot` — abstract; **single `abstract protected function apply(DomainEvent $event): void`** (subclasses dispatch via `match (true) { $event instanceof X => ... }`); `recordThat()` calls `apply()` synchronously then appends; `replay()` enables `isReplaying` guard; `recordThat()` during replay throws `ApplyDuringReplayException`
- `Monadial\Nexus\Ddd\Core\Aggregate\EventSourcedAggregateRootAccessor` — friend-class decorator: `replayOn`, `popRecordedEventsFrom`, `extractVersion`, `rehydrateVersionOn`
- `Monadial\Nexus\Ddd\Core\Aggregate\StatefulAggregateRoot` — abstract base for stateful aggregates
- `Monadial\Nexus\Ddd\Core\Exception\NexusDddException` — framework-wiring exception root
- `Monadial\Nexus\Ddd\Core\Exception\DomainException` — domain-rule-violation root
- `Monadial\Nexus\Ddd\Core\Exception\OptimisticLockException` — already extends `DomainException`; **does NOT implement any retry-classifier marker** — the markers `TerminalFailure` / `TransientFailure` live in `nexus-ddd-messaging`, and bus middleware classifies retry behavior by exception type, NOT via marker interfaces on the aggregate-side exceptions
- `Monadial\Nexus\Persistence\Event\EventStore` — parent interface with 4 methods: `persist`, `load(id, fromSeq=0, toSeq=PHP_INT_MAX): iterable`, `deleteUpTo`, `highestSequenceNr`
- `Monadial\Nexus\Persistence\Event\EventEnvelope` — wraps a `DomainEvent` with sequence number, persistence id, occurred-at
- `Monadial\Nexus\Persistence\PersistenceId` — `(entityType, entityId)` value object

**Spec/code drift notes the plan reconciles:**
- v6 spec §9.1.2 says exceptions implement `RetryableFailure`; actual marker is `TransientFailure` (in messaging). Plan does NOT make aggregate exceptions implement either marker — bus middleware classifies by type to avoid a cross-package marker dependency.
- v6 spec §6.4 references `applyXxx()` per-event method names; shipped `EventSourcedAggregateRoot` uses single `apply()` + `match (true)`. Plan aligns fixtures and `ReplaySafeApplyRule` with the shipped pattern.

**Tech Stack:** PHP 8.5+, Psalm strict (level 1), PER-CS2.0, fp4php/functional (Option<T>), psr/clock, monadial/nexus-persistence (existing — provides `EventStore`, `SnapshotStore`, `PersistenceId`, `EventEnvelope`), nexus-actors/ddd-core (already shipped). Symfony/uid for ULID. PHPUnit 13.

---

## File Structure

```
packages/nexus-ddd-aggregate/
├── composer.json
├── psalm.xml
├── phpcs.xml
├── src/
│   ├── Repository/
│   │   ├── AggregateRepository.php             // interface
│   │   └── GenericAggregateRepository.php      // concrete
│   ├── Strategy/
│   │   ├── PersistenceStrategy.php             // public interface
│   │   ├── EventSourcedPersister.php           // @internal
│   │   ├── StatefulPersister.php               // @internal
│   │   ├── CompositePersistenceStrategy.php    // dispatches by kind
│   │   ├── EventSourcing/
│   │   │   ├── EventSourcingStrategy.php       // EventSourcedPersister using VersionedEventStore + SnapshotStore
│   │   │   ├── InMemoryEventSourcingStrategy.php
│   │   │   ├── SnapshotStrategy.php            // when to snapshot
│   │   │   ├── NeverSnapshot.php
│   │   │   ├── EveryNEvents.php
│   │   │   └── OnPredicate.php
│   │   └── Stateful/
│   │       └── InMemoryStatefulStrategy.php
│   ├── Event/
│   │   ├── VersionedEventStore.php             // extends nexus-persistence's EventStore
│   │   ├── InMemoryVersionedEventStore.php
│   │   ├── Stream/
│   │   │   ├── StreamStrategy.php              // public interface
│   │   │   ├── StreamName.php                  // value object
│   │   │   ├── SingleStreamStrategy.php
│   │   │   └── PerAggregateTypeStreamStrategy.php
│   │   ├── Attribute/
│   │   │   ├── Event.php                       // #[Event(name:, version:)]
│   │   │   └── TombstoneEvent.php              // #[TombstoneEvent(name:, removedAt:)]
│   │   └── Registry/
│   │       └── EventNameRegistry.php           // boot-time uniqueness check
│   ├── Versioning/
│   │   ├── Upcaster.php                        // interface
│   │   ├── SnapshotUpcaster.php                // interface
│   │   ├── UpcasterPipeline.php                // interface
│   │   ├── DefaultUpcasterPipeline.php         // concrete
│   │   └── UpcasterRegistry.php                // boot-time chain validation
│   └── Exception/
│       ├── AggregateAlreadyExistsException.php
│       ├── MultiAggregateTransactionException.php
│       ├── EventNameCollisionException.php     // boot-time uniqueness
│       └── UpcasterChainGapException.php       // boot-time chain validity
└── tests/
    ├── Unit/         (mirrors src/)
    │   └── Fitness/  (architectural fitness functions)
    └── Support/      (RecordingVersionedEventStore, abstract contract tests, fixture aggregates)
```

`OptimisticLockException` already lives in `nexus-ddd-core` (shipped in P0). Re-used as-is.

---

## Phase 0 — Branch cut

Already done — branch `feat/nexus-ddd-aggregate` is cut from `feat/nexus-ddd-messaging` HEAD.

- [ ] **Step 1: Verify branch state**

```bash
git rev-parse --abbrev-ref HEAD
# Expect: feat/nexus-ddd-aggregate
git log --oneline -1
# Expect: 15cea343 docs(ddd-umbrella): v6 expert-panel revisions...
```

---

## Phase 1 — Package skeleton

**Files:**
- Create: `packages/nexus-ddd-aggregate/composer.json`
- Create: `packages/nexus-ddd-aggregate/psalm.xml`
- Create: `packages/nexus-ddd-aggregate/phpcs.xml`
- Create: `packages/nexus-ddd-aggregate/.gitignore`
- Modify: root `composer.json` (autoload paths)
- Modify: root `phpunit.xml` — add `packages/nexus-ddd-aggregate/tests/Unit` to `unit` testsuite + `packages/nexus-ddd-aggregate/src` to `<source>` whitelist (CRITICAL — without this, `#[CoversClass]` triggers "is not a valid target for code coverage" warnings under `failOnWarning=true`, exactly as we hit on PR #32)
- Modify: root `deptrac.yaml` — add a layer for `nexus-ddd-aggregate` and forbid imports of `nexus-ddd-messaging` from this package's `src/` (the package depends on messaging contracts only via marker types — none in this package's first cut, so the layer can be strict).

- [ ] **Step 1: Write `packages/nexus-ddd-aggregate/composer.json`**

```json
{
  "name": "nexus-actors/ddd-aggregate",
  "description": "Nexus DDD Framework — aggregate persistence (Repository, PersistenceStrategy, VersionedEventStore, in-memory ES + stateful strategies, upcaster pipeline).",
  "type": "library",
  "license": "MIT",
  "require": {
    "php": ">=8.5",
    "fp4php/functional": "^6.0",
    "monadial/nexus-persistence": "dev-main",
    "nexus-actors/ddd-core": "dev-main",
    "psr/clock": "^1.0"
  },
  "require-dev": {
    "phpunit/phpunit": "^13.0"
  },
  "autoload": {
    "psr-4": { "Monadial\\Nexus\\Ddd\\Aggregate\\": "src/" }
  },
  "autoload-dev": {
    "psr-4": { "Monadial\\Nexus\\Ddd\\Aggregate\\Tests\\": "tests/" }
  }
}
```

- [ ] **Step 2: `psalm.xml` and `phpcs.xml`** — mirror the `nexus-ddd-messaging` package conventions (see `packages/nexus-ddd-messaging/psalm.xml`).

- [ ] **Step 3: Update root `composer.json`** — add `nexus-actors/ddd-aggregate` to the path-repositories section if other packages do this, or skip if root autoload already includes packages/* via wildcard.

- [ ] **Step 4: Update root `phpunit.xml`**

```xml
<source>
    <include>
        <!-- existing entries -->
        <directory>packages/nexus-ddd-aggregate/src</directory>
        <directory>packages/nexus-ddd-aggregate/tests/Support</directory>
    </include>
</source>
<testsuites>
    <testsuite name="unit">
        <!-- existing -->
        <directory>packages/nexus-ddd-aggregate/tests/Unit</directory>
    </testsuite>
</testsuites>
```

- [ ] **Step 5: Update root `deptrac.yaml`**

Add layer `NexusDddAggregate` matching `packages/nexus-ddd-aggregate/src/`. Allowed dependencies: `NexusDddCore`, `NexusPersistence`, `Vendor` (for psr/clock, fp4php). Forbidden: `NexusDddMessaging`, `NexusDddBus`, `NexusDddDoctrine`, `NexusDddDbal`.

- [ ] **Step 6: Verify pipeline**

```bash
docker compose exec -T php composer dump-autoload --quiet
docker compose exec -T php vendor/bin/phpunit --testsuite=unit
docker compose exec -T php vendor/bin/psalm --no-cache
docker compose exec -T php vendor/bin/deptrac
```

All clean.

- [ ] **Step 7: Commit**

```bash
git add packages/nexus-ddd-aggregate/composer.json packages/nexus-ddd-aggregate/psalm.xml packages/nexus-ddd-aggregate/phpcs.xml packages/nexus-ddd-aggregate/.gitignore composer.json phpunit.xml deptrac.yaml
git commit -m "feat(ddd-aggregate): package skeleton + composer/phpunit/deptrac wiring"
```

---

## Phase 2 — Exception hierarchy

**Files:**
- Create: `packages/nexus-ddd-aggregate/src/Exception/AggregateAlreadyExistsException.php`
- Create: `packages/nexus-ddd-aggregate/src/Exception/MultiAggregateTransactionException.php`
- Create: `packages/nexus-ddd-aggregate/src/Exception/EventNameCollisionException.php`
- Create: `packages/nexus-ddd-aggregate/src/Exception/UpcasterChainGapException.php`
- Test: `packages/nexus-ddd-aggregate/tests/Unit/Exception/ExceptionHierarchyTest.php`

**Inheritance roots (locked):**
- `AggregateAlreadyExistsException` extends `DomainException` — the conflict is a domain fact (someone else already exists). Parallels shipped `OptimisticLockException`, which also extends `DomainException`.
- `MultiAggregateTransactionException` extends `NexusDddException` — framework-wiring fault (handler misbehavior).
- `EventNameCollisionException` extends `NexusDddException` — boot-time framework fault.
- `UpcasterChainGapException` extends `NexusDddException` — boot-time framework fault.

**No retry-classifier markers.** v6 spec §9.1.2 references `RetryableFailure`/`TerminalFailure` markers; actual code uses `TransientFailure`/`TerminalFailure` (in messaging). Bus middleware classifies retry behavior by exception type, not via marker interfaces on the aggregate-side exceptions. This avoids a cross-package marker dependency from aggregate → messaging.

- [ ] **Step 1: Write the exception hierarchy test FIRST**

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Exception;

use Monadial\Nexus\Ddd\Aggregate\Exception\AggregateAlreadyExistsException;
use Monadial\Nexus\Ddd\Aggregate\Exception\EventNameCollisionException;
use Monadial\Nexus\Ddd\Aggregate\Exception\MultiAggregateTransactionException;
use Monadial\Nexus\Ddd\Aggregate\Exception\UpcasterChainGapException;
use Monadial\Nexus\Ddd\Core\Exception\DomainException;
use Monadial\Nexus\Ddd\Core\Exception\NexusDddException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AggregateAlreadyExistsException::class)]
#[CoversClass(MultiAggregateTransactionException::class)]
#[CoversClass(EventNameCollisionException::class)]
#[CoversClass(UpcasterChainGapException::class)]
final class ExceptionHierarchyTest extends TestCase
{
    #[Test]
    public function aggregateAlreadyExistsExtendsDomainException(): void
    {
        $e = AggregateAlreadyExistsException::for('App\\Order', 'order-1');
        self::assertInstanceOf(DomainException::class, $e);
        self::assertStringContainsString('App\\Order', $e->getMessage());
        self::assertStringContainsString('order-1', $e->getMessage());
    }

    #[Test]
    public function multiAggregateTransactionExtendsNexusDddException(): void
    {
        $e = MultiAggregateTransactionException::secondAggregateInTransaction('App\\Order', 'order-1', 'App\\Customer', 'cust-1');
        self::assertInstanceOf(NexusDddException::class, $e);
    }

    #[Test]
    public function eventNameCollisionExtendsNexusDddException(): void
    {
        $e = EventNameCollisionException::between('orders.OrderPlaced', 'App\\OldOrderPlaced', 'App\\OrderPlaced');
        self::assertInstanceOf(NexusDddException::class, $e);
    }

    #[Test]
    public function upcasterChainGapExtendsNexusDddException(): void
    {
        $e = UpcasterChainGapException::missingUpcaster('orders.OrderPlaced', 1, 2);
        self::assertInstanceOf(NexusDddException::class, $e);
    }
}
```

- [ ] **Step 2: Run, confirm 4 failures (classes don't exist)**

- [ ] **Step 3: Write the four classes**

```php
// AggregateAlreadyExistsException.php
final class AggregateAlreadyExistsException extends DomainException
{
    public static function for(string $aggregateClass, string $aggregateId): self
    {
        return new self(sprintf(
            'Aggregate %s with id %s already exists. (add() or save(version=0) collided with a previously-persisted aggregate.)',
            $aggregateClass, $aggregateId,
        ));
    }
}

// MultiAggregateTransactionException.php
final class MultiAggregateTransactionException extends NexusDddException
{
    public static function secondAggregateInTransaction(
        string $firstAggregateClass, string $firstAggregateId,
        string $secondAggregateClass, string $secondAggregateId,
    ): self {
        return new self(sprintf(
            'A handler attempted to persist a second aggregate within the same transaction: first %s(%s); second %s(%s). One-aggregate-per-transaction rule violated. Use process managers + outbox for cross-aggregate coordination.',
            $firstAggregateClass, $firstAggregateId, $secondAggregateClass, $secondAggregateId,
        ));
    }
}

// EventNameCollisionException.php
final class EventNameCollisionException extends NexusDddException
{
    public static function between(string $eventName, string $classA, string $classB): self
    {
        return new self(sprintf(
            'Event name %s is declared by both %s and %s. Each (eventName, schemaVersion) MUST be unique across all DomainEvent classes.',
            $eventName, $classA, $classB,
        ));
    }
}

// UpcasterChainGapException.php
final class UpcasterChainGapException extends NexusDddException
{
    public static function missingUpcaster(string $eventName, int $fromVersion, int $toVersion): self
    {
        return new self(sprintf(
            'No upcaster registered for %s v%d → v%d. The upcaster chain has a gap; replay would fail mid-stream. Register an Upcaster covering this transition before deploying.',
            $eventName, $fromVersion, $toVersion,
        ));
    }
}
```

- [ ] **Step 4: Run, verify 4 pass**
- [ ] **Step 5: Psalm + PHPCS clean**
- [ ] **Step 6: Commit**

```bash
git commit -m "feat(ddd-aggregate): exception hierarchy (4 exceptions across DomainException + NexusDddException roots)"
```

---

## Phase 3 — `VersionedEventStore` interface + `InMemoryVersionedEventStore`

**Files:**
- Create: `packages/nexus-ddd-aggregate/src/Event/VersionedEventStore.php`
- Create: `packages/nexus-ddd-aggregate/src/Event/InMemoryVersionedEventStore.php`
- Test: `packages/nexus-ddd-aggregate/tests/Support/VersionedEventStoreContractTest.php` (abstract)
- Test: `packages/nexus-ddd-aggregate/tests/Unit/Event/InMemoryVersionedEventStoreContractTest.php` (concrete)

`VersionedEventStore extends nexus-persistence's EventStore`, adding `appendIfVersion(PersistenceId, int $expectedVersion, EventEnvelope ...$events): void`. The implementation MUST enforce versioning via a logical UNIQUE constraint — for the in-memory impl, that's a count check on the stream array. For DBAL impls (future package), it's a real DB constraint.

**Parent `EventStore` signature (from `packages/nexus-persistence/src/Event/EventStore.php`):**
```php
interface EventStore
{
    public function persist(PersistenceId $id, EventEnvelope ...$events): void;
    public function load(PersistenceId $id, int $fromSequenceNr = 0, int $toSequenceNr = PHP_INT_MAX): iterable;
    public function deleteUpTo(PersistenceId $id, int $toSequenceNr): void;
    public function highestSequenceNr(PersistenceId $id): int;
}
```

The in-memory impl satisfies all four parent methods + the new `appendIfVersion`.

- [ ] **Step 1: Write the abstract contract test**

```php
abstract class VersionedEventStoreContractTest extends TestCase
{
    abstract protected function createStore(): VersionedEventStore;
    abstract protected function buildEnvelope(int $sequenceNr, object $event): EventEnvelope;

    #[Test]
    public function appendIfVersionWithExpectedZeroSucceedsOnEmptyStream(): void
    {
        $store = $this->createStore();
        $id = PersistenceId::of('Order', 'order-1');
        $store->appendIfVersion($id, 0, $this->buildEnvelope(1, new \stdClass()));
        self::assertSame(1, $store->highestSequenceNr($id));
    }

    #[Test]
    public function appendIfVersionWithMatchingExpectedSucceeds(): void
    {
        $store = $this->createStore();
        $id = PersistenceId::of('Order', 'order-1');
        $store->appendIfVersion($id, 0, $this->buildEnvelope(1, new \stdClass()));
        $store->appendIfVersion($id, 1, $this->buildEnvelope(2, new \stdClass()));
        self::assertSame(2, $store->highestSequenceNr($id));
    }

    #[Test]
    public function appendIfVersionWithStaleExpectedThrowsOptimisticLockException(): void
    {
        $store = $this->createStore();
        $id = PersistenceId::of('Order', 'order-1');
        $store->appendIfVersion($id, 0, $this->buildEnvelope(1, new \stdClass()));
        $this->expectException(OptimisticLockException::class);
        $store->appendIfVersion($id, 0, $this->buildEnvelope(1, new \stdClass()));
    }

    #[Test]
    public function appendIfVersionWithFutureExpectedThrowsOptimisticLockException(): void
    {
        $store = $this->createStore();
        $id = PersistenceId::of('Order', 'order-1');
        $this->expectException(OptimisticLockException::class);
        $store->appendIfVersion($id, 5, $this->buildEnvelope(6, new \stdClass()));
    }

    #[Test]
    public function appendIfVersionMultipleEventsAppliedAtomically(): void
    {
        $store = $this->createStore();
        $id = PersistenceId::of('Order', 'order-1');
        $store->appendIfVersion($id, 0,
            $this->buildEnvelope(1, new \stdClass()),
            $this->buildEnvelope(2, new \stdClass()),
            $this->buildEnvelope(3, new \stdClass()),
        );
        self::assertSame(3, $store->highestSequenceNr($id));
    }

    #[Test]
    public function loadReturnsEventsInSequenceOrder(): void
    {
        $store = $this->createStore();
        $id = PersistenceId::of('Order', 'order-1');
        $e1 = $this->buildEnvelope(1, new \stdClass());
        $e2 = $this->buildEnvelope(2, new \stdClass());
        $store->appendIfVersion($id, 0, $e1, $e2);
        $loaded = iterator_to_array($store->load($id));
        self::assertSame([$e1, $e2], $loaded);
    }

    #[Test]
    public function loadWithSequenceRangeReturnsSubrange(): void
    {
        $store = $this->createStore();
        $id = PersistenceId::of('Order', 'order-1');
        $envelopes = [
            $this->buildEnvelope(1, new \stdClass()),
            $this->buildEnvelope(2, new \stdClass()),
            $this->buildEnvelope(3, new \stdClass()),
        ];
        $store->appendIfVersion($id, 0, ...$envelopes);
        $sub = iterator_to_array($store->load($id, 2, 3));
        self::assertSame([$envelopes[1], $envelopes[2]], $sub);
    }

    #[Test]
    public function deleteUpToRemovesEventsThroughGivenSequence(): void
    {
        $store = $this->createStore();
        $id = PersistenceId::of('Order', 'order-1');
        $store->appendIfVersion($id, 0,
            $this->buildEnvelope(1, new \stdClass()),
            $this->buildEnvelope(2, new \stdClass()),
            $this->buildEnvelope(3, new \stdClass()),
        );
        $store->deleteUpTo($id, 2);
        self::assertSame(1, count(iterator_to_array($store->load($id))));
    }

    #[Test]
    public function highestSequenceNrIsZeroForEmptyStream(): void
    {
        self::assertSame(0, $this->createStore()->highestSequenceNr(PersistenceId::of('Order', 'absent')));
    }
}
```

- [ ] **Step 2: Write the interface**

```php
namespace Monadial\Nexus\Ddd\Aggregate\Event;

use Monadial\Nexus\Ddd\Core\Exception\OptimisticLockException;
use Monadial\Nexus\Persistence\Event\EventEnvelope;
use Monadial\Nexus\Persistence\Event\EventStore;
use Monadial\Nexus\Persistence\PersistenceId;

/**
 * @psalm-api
 *
 * Versioned-append event store. Atomically appends events iff the current
 * highest sequence number for `$persistenceId` equals `$expectedVersion`;
 * mismatch raises `OptimisticLockException` and no events are persisted.
 *
 * MUST implementation rules (per umbrella spec v6 §9.2.1):
 *   - Version check via UNIQUE(aggregate_id, aggregate_version) — read-then-write
 *     across two queries races under all isolation levels and is forbidden.
 *   - Isolation level: READ COMMITTED. SQLSTATE 40001 (serialization failure)
 *     and InnoDB deadlocks (1213) MUST be mapped to OptimisticLockException
 *     for uniformity.
 *   - `appendIfVersion` participates in the caller's open transaction.
 *     The outbox INSERT (when used) shares the same transaction.
 */
interface VersionedEventStore extends EventStore
{
    /**
     * @throws OptimisticLockException when expectedVersion does not match
     *         the current highest sequence number.
     */
    public function appendIfVersion(
        PersistenceId $persistenceId,
        int $expectedVersion,
        EventEnvelope ...$events,
    ): void;
}
```

- [ ] **Step 3: Write the in-memory impl — ALL FOUR PARENT METHODS plus appendIfVersion**

```php
namespace Monadial\Nexus\Ddd\Aggregate\Event;

use Monadial\Nexus\Ddd\Core\Exception\OptimisticLockException;
use Monadial\Nexus\Persistence\Event\EventEnvelope;
use Monadial\Nexus\Persistence\PersistenceId;
use Override;

/**
 * @psalm-api
 *
 * In-memory implementation. TESTS-ONLY (and single-process Fiber-only).
 * Uses an associative array keyed by PersistenceId.value() with a list
 * of EventEnvelopes; the count of events is the "current version".
 */
final class InMemoryVersionedEventStore implements VersionedEventStore
{
    /** @var array<string, list<EventEnvelope>> */
    private array $streams = [];

    #[Override]
    public function appendIfVersion(PersistenceId $id, int $expectedVersion, EventEnvelope ...$events): void
    {
        $key = $id->value();
        $current = count($this->streams[$key] ?? []);
        if ($current !== $expectedVersion) {
            throw new OptimisticLockException(
                $id->entityType(),  // class-string
                $id->entityId(),
                $expectedVersion,
                $current,
            );
        }
        foreach ($events as $event) {
            $this->streams[$key][] = $event;
        }
    }

    #[Override]
    public function persist(PersistenceId $id, EventEnvelope ...$events): void
    {
        // Unconditional path (matches nexus-persistence's contract for non-OCC actor-based ES).
        $key = $id->value();
        foreach ($events as $event) {
            $this->streams[$key][] = $event;
        }
    }

    /** @return iterable<EventEnvelope> */
    #[Override]
    public function load(PersistenceId $id, int $fromSequenceNr = 0, int $toSequenceNr = PHP_INT_MAX): iterable
    {
        $stream = $this->streams[$id->value()] ?? [];
        foreach ($stream as $envelope) {
            $seq = $envelope->sequenceNr;
            if ($seq >= $fromSequenceNr && $seq <= $toSequenceNr) {
                yield $envelope;
            }
        }
    }

    #[Override]
    public function deleteUpTo(PersistenceId $id, int $toSequenceNr): void
    {
        $key = $id->value();
        $stream = $this->streams[$key] ?? [];
        $this->streams[$key] = array_values(array_filter(
            $stream,
            static fn(EventEnvelope $e) => $e->sequenceNr > $toSequenceNr,
        ));
    }

    #[Override]
    public function highestSequenceNr(PersistenceId $id): int
    {
        $stream = $this->streams[$id->value()] ?? [];
        return $stream === [] ? 0 : end($stream)->sequenceNr;
    }
}
```

(Verify `EventEnvelope` field names and `PersistenceId::entityType()/entityId()/value()` accessors against the actual shipped code before writing — adjust if signatures differ.)

- [ ] **Step 4: Concrete contract subclass**

```php
#[CoversClass(InMemoryVersionedEventStore::class)]
final class InMemoryVersionedEventStoreContractTest extends VersionedEventStoreContractTest
{
    #[Override]
    protected function createStore(): VersionedEventStore
    {
        return new InMemoryVersionedEventStore();
    }

    #[Override]
    protected function buildEnvelope(int $sequenceNr, object $event): EventEnvelope
    {
        // Construct using nexus-persistence's actual EventEnvelope factory or constructor.
        // Inspect packages/nexus-persistence/src/Event/EventEnvelope.php for the exact signature.
        return /* ... */;
    }
}
```

- [ ] **Step 5: Run tests + Psalm + PHPCS, all clean**
- [ ] **Step 6: Commit**

```bash
git commit -m "feat(ddd-aggregate): VersionedEventStore + InMemoryVersionedEventStore + abstract contract test"
```

---

## Phase 4 — `StreamStrategy` + `StreamName`

**Files:**
- Create: `packages/nexus-ddd-aggregate/src/Event/Stream/StreamName.php`
- Create: `packages/nexus-ddd-aggregate/src/Event/Stream/StreamStrategy.php`
- Create: `packages/nexus-ddd-aggregate/src/Event/Stream/SingleStreamStrategy.php`
- Create: `packages/nexus-ddd-aggregate/src/Event/Stream/PerAggregateTypeStreamStrategy.php`
- Tests for each

`StreamStrategy::streamFor(class-string, Identifier): StreamName` is the entire public surface. **No `tableFor()` method** — physical layout concern moved to internal DBAL/Doctrine impls behind a private `TableNameResolver` (per v6 §9.3).

- [ ] **Step 1: Write `StreamName` test (equals + value)**
- [ ] **Step 2: Write `StreamName`** — `final readonly class` with single `string $value` property + `equals(self): bool`
- [ ] **Step 3: Write `StreamStrategy` interface (single method)**
- [ ] **Step 4: TDD `SingleStreamStrategy`**
   - Test: returns `new StreamName('ddd_events')` regardless of class/id
   - Impl: `public function streamFor(string $aggregateClass, Identifier $id): StreamName { return new StreamName('ddd_events'); }`
- [ ] **Step 5: TDD `PerAggregateTypeStreamStrategy`**
   - Test: `streamFor(Order::class, ...)` returns `StreamName('ddd_events_orders')`
   - Test: `streamFor(Customer::class, ...)` returns `StreamName('ddd_events_customers')`
   - Impl: lowercase + snake_case the class short-name + pluralize? — actually the v6 spec doesn't require pluralization; just snake_case the short-name. Document this.
- [ ] **Step 6: Commit**

```bash
git commit -m "feat(ddd-aggregate): StreamStrategy + StreamName + SingleStreamStrategy + PerAggregateTypeStreamStrategy"
```

---

## Phase 5 — `#[Event]` + `#[TombstoneEvent]` attributes + `EventNameRegistry`

**Files:**
- Create: `packages/nexus-ddd-aggregate/src/Event/Attribute/Event.php`
- Create: `packages/nexus-ddd-aggregate/src/Event/Attribute/TombstoneEvent.php`
- Create: `packages/nexus-ddd-aggregate/src/Event/Registry/EventNameRegistry.php`
- Tests

`#[Event(name: string, version: int)]` decorates `DomainEvent` classes; `#[TombstoneEvent(name: string, removedAt: string)]` marks deleted events. `EventNameRegistry::scan(iterable<class-string>)` reads attributes via Reflection, validates `(name, version)` uniqueness, throws `EventNameCollisionException` on duplicate.

- [ ] **Step 1: Write `Event` attribute test**

```php
#[CoversClass(Event::class)]
final class EventAttributeTest extends TestCase
{
    #[Test]
    public function constructsWithNameAndVersion(): void
    {
        $attr = new Event(name: 'orders.OrderPlaced', version: 2);
        self::assertSame('orders.OrderPlaced', $attr->name);
        self::assertSame(2, $attr->version);
    }

    #[Test]
    public function defaultsVersionToOne(): void
    {
        $attr = new Event(name: 'orders.OrderPlaced');
        self::assertSame(1, $attr->version);
    }
}
```

- [ ] **Step 2: Write `Event` attribute class**

```php
namespace Monadial\Nexus\Ddd\Aggregate\Event\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Event
{
    public function __construct(
        public string $name,
        public int $version = 1,
    ) {}
}
```

- [ ] **Step 3: TDD `TombstoneEvent` similarly** — fields `name: string`, `removedAt: string` (semver)

- [ ] **Step 4: Write `EventNameRegistry` test**

```php
#[CoversClass(EventNameRegistry::class)]
final class EventNameRegistryTest extends TestCase
{
    #[Test]
    public function scanRegistersEventClasses(): void
    {
        $registry = EventNameRegistry::scan([GoodOrderPlacedV1::class]);
        self::assertSame(GoodOrderPlacedV1::class, $registry->classFor('orders.OrderPlaced', 1));
    }

    #[Test]
    public function scanThrowsOnDuplicateNameVersion(): void
    {
        $this->expectException(EventNameCollisionException::class);
        EventNameRegistry::scan([GoodOrderPlacedV1::class, DuplicateOrderPlacedV1::class]);
    }

    #[Test]
    public function scanIgnoresClassesWithoutEventAttribute(): void
    {
        $registry = EventNameRegistry::scan([\stdClass::class]);
        self::assertCount(0, $registry->all());
    }

    #[Test]
    public function classForReturnsNoneOnMiss(): void
    {
        $registry = EventNameRegistry::scan([]);
        self::assertTrue($registry->classFor('absent', 1) === null);
    }
}
```

- [ ] **Step 5: Write `EventNameRegistry` impl**

```php
namespace Monadial\Nexus\Ddd\Aggregate\Event\Registry;

use Monadial\Nexus\Ddd\Aggregate\Event\Attribute\Event;
use Monadial\Nexus\Ddd\Aggregate\Exception\EventNameCollisionException;
use ReflectionClass;

final class EventNameRegistry
{
    /** @param array<string, class-string> $byNameVersion key = "name@version" */
    private function __construct(private readonly array $byNameVersion) {}

    /**
     * @param iterable<class-string> $candidateClasses
     * @throws EventNameCollisionException
     */
    public static function scan(iterable $candidateClasses): self
    {
        $byNameVersion = [];
        foreach ($candidateClasses as $class) {
            $reflection = new ReflectionClass($class);
            $attrs = $reflection->getAttributes(Event::class);
            if ($attrs === []) continue;
            $event = $attrs[0]->newInstance();
            $key = $event->name . '@' . $event->version;
            if (isset($byNameVersion[$key])) {
                throw EventNameCollisionException::between($event->name, $byNameVersion[$key], $class);
            }
            $byNameVersion[$key] = $class;
        }
        return new self($byNameVersion);
    }

    /** @return class-string|null */
    public function classFor(string $name, int $version): ?string
    {
        return $this->byNameVersion[$name . '@' . $version] ?? null;
    }

    /** @return array<string, class-string> */
    public function all(): array
    {
        return $this->byNameVersion;
    }
}
```

- [ ] **Step 6: Tests pass + Psalm + PHPCS clean**
- [ ] **Step 7: Commit**

```bash
git commit -m "feat(ddd-aggregate): #[Event] + #[TombstoneEvent] attributes + EventNameRegistry boot-time uniqueness check"
```

---

## Phase 6 — `Upcaster` + `SnapshotUpcaster` + `UpcasterPipeline`

**Files:**
- Create: `packages/nexus-ddd-aggregate/src/Versioning/Upcaster.php`
- Create: `packages/nexus-ddd-aggregate/src/Versioning/SnapshotUpcaster.php`
- Create: `packages/nexus-ddd-aggregate/src/Versioning/UpcasterPipeline.php`
- Create: `packages/nexus-ddd-aggregate/src/Versioning/DefaultUpcasterPipeline.php`
- Create: `packages/nexus-ddd-aggregate/src/Versioning/UpcasterRegistry.php`
- Tests for each

**Key APIs (per v6 §10.2):**
- `Upcaster::eventName(): string` / `fromVersion(): int` / `toVersion(): int` (toVersion = fromVersion + 1)
- `Upcaster::upcast(array $payload, MessageMetadata $metadata): array` — pure
- `UpcasterPipeline::upcast(string $eventName, int $fromVersion, array $payload, MessageMetadata $metadata): array` — to-latest (used by aggregate replay)
- `UpcasterPipeline::upcastTo(string $eventName, int $fromVersion, int $targetVersion, array $payload, MessageMetadata $metadata): array` — pin-to-version (used by projection rebuilds)
- `UpcasterRegistry::scan(iterable<class-string>): UpcasterPipeline` — walks declared upcasters; validates the chain has no gaps; throws `UpcasterChainGapException`

**Note:** `MessageMetadata` lives in `nexus-ddd-messaging`. That's a cross-package dependency. **Decision:** define a local `Versioning\PayloadContext` value object that the upcasters receive instead of `MessageMetadata` directly — keeps the aggregate package independent of messaging. The `EventSourcingStrategy` constructs the `PayloadContext` from the persisted envelope's metadata at replay time.

- [ ] **Step 1: Write `PayloadContext` value object first**

```php
namespace Monadial\Nexus\Ddd\Aggregate\Versioning;

use DateTimeImmutable;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Read-only context passed to upcasters. Subset of MessageMetadata that
 * upcasters legitimately need; constructed from the persisted envelope's
 * metadata at replay time. Keeps aggregate package independent of
 * nexus-ddd-messaging.
 */
final readonly class PayloadContext
{
    public function __construct(
        public string $eventName,
        public int $fromVersion,
        public DateTimeImmutable $occurredAt,
    ) {}
}
```

- [ ] **Step 2: TDD `Upcaster` interface**

```php
interface Upcaster
{
    public function eventName(): string;
    public function fromVersion(): int;
    public function toVersion(): int;
    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function upcast(array $payload, PayloadContext $context): array;
}
```

- [ ] **Step 3: TDD `SnapshotUpcaster` interface (similar shape — fields keyed off snapshot's stateVersion instead of event version)**

- [ ] **Step 4: TDD `UpcasterPipeline` interface**

```php
interface UpcasterPipeline
{
    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function upcast(string $eventName, int $fromVersion, array $payload, PayloadContext $context): array;

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function upcastTo(string $eventName, int $fromVersion, int $targetVersion, array $payload, PayloadContext $context): array;
}
```

- [ ] **Step 5: TDD `DefaultUpcasterPipeline`**

Test cases:
- `upcast()` with no upcasters returns payload unchanged
- `upcast()` with one upcaster v1→v2 transforms payload
- `upcast()` with chain v1→v2→v3 walks both
- `upcast()` skips upcasters for other event names
- `upcastTo($targetVersion=2)` stops at v2 even if v3 upcaster exists
- `upcastTo($targetVersion=fromVersion)` returns input unchanged

```php
final class DefaultUpcasterPipeline implements UpcasterPipeline
{
    /** @var array<string, array<int, Upcaster>> eventName => fromVersion => Upcaster */
    public function __construct(private readonly array $byEventAndFrom) {}

    public function upcast(string $eventName, int $fromVersion, array $payload, PayloadContext $context): array
    {
        $current = $payload;
        $version = $fromVersion;
        while (isset($this->byEventAndFrom[$eventName][$version])) {
            $upcaster = $this->byEventAndFrom[$eventName][$version];
            $current = $upcaster->upcast($current, $context);
            $version = $upcaster->toVersion();
        }
        return $current;
    }

    public function upcastTo(string $eventName, int $fromVersion, int $targetVersion, array $payload, PayloadContext $context): array
    {
        if ($fromVersion >= $targetVersion) return $payload;
        $current = $payload;
        $version = $fromVersion;
        while ($version < $targetVersion && isset($this->byEventAndFrom[$eventName][$version])) {
            $upcaster = $this->byEventAndFrom[$eventName][$version];
            $current = $upcaster->upcast($current, $context);
            $version = $upcaster->toVersion();
        }
        return $current;
    }
}
```

- [ ] **Step 6: TDD `UpcasterRegistry::scan`**

Test cases:
- Empty input → empty pipeline
- One upcaster → registered
- Chain v1→v2 + v2→v3 → both registered, no gap exception
- Chain v1→v2 + v3→v4 (gap at 2→3) → throws `UpcasterChainGapException`
- Two upcasters with the same `(eventName, fromVersion)` → throws (duplicate, treat as gap-equivalent)

```php
final class UpcasterRegistry
{
    /**
     * @param iterable<class-string<Upcaster>> $classes
     * @throws UpcasterChainGapException
     */
    public static function scan(iterable $classes): UpcasterPipeline
    {
        /** @var array<string, array<int, Upcaster>> $registered */
        $registered = [];
        $maxVersionByEvent = [];
        foreach ($classes as $class) {
            $upcaster = new $class();  // assumes no-arg ctor; document this
            $event = $upcaster->eventName();
            $from = $upcaster->fromVersion();
            $to = $upcaster->toVersion();
            $registered[$event][$from] = $upcaster;
            $maxVersionByEvent[$event] = max($maxVersionByEvent[$event] ?? 1, $to);
        }
        // Gap check: for every event with versions [1..max], every (i, i+1) must be covered.
        foreach ($registered as $event => $byFrom) {
            $max = $maxVersionByEvent[$event];
            for ($v = 1; $v < $max; $v++) {
                if (!isset($byFrom[$v])) {
                    throw UpcasterChainGapException::missingUpcaster($event, $v, $v + 1);
                }
            }
        }
        return new DefaultUpcasterPipeline($registered);
    }
}
```

- [ ] **Step 7: Run tests + Psalm + PHPCS clean**
- [ ] **Step 8: Commit**

```bash
git commit -m "feat(ddd-aggregate): Upcaster + SnapshotUpcaster + UpcasterPipeline + DefaultUpcasterPipeline + UpcasterRegistry boot-time chain validation"
```

---

## Phase 7 — `SnapshotStrategy` + impls

**Files:**
- Create: `packages/nexus-ddd-aggregate/src/Strategy/EventSourcing/SnapshotStrategy.php`
- Create: `packages/nexus-ddd-aggregate/src/Strategy/EventSourcing/NeverSnapshot.php`
- Create: `packages/nexus-ddd-aggregate/src/Strategy/EventSourcing/EveryNEvents.php`
- Create: `packages/nexus-ddd-aggregate/src/Strategy/EventSourcing/OnPredicate.php`
- Tests

**Per v6 §25.6.4:** snapshot writes happen *after* the OCC append commits, in a separate transaction. `SnapshotStrategy::shouldSnapshot()` is consulted *after* successful persist; the actual snapshot write is non-fatal — failure is logged, never throws to the handler. This eliminates the "ahead-of-truth" scenario where a snapshot write succeeds but the subsequent event write fails.

The strategy decides *whether* to snapshot at this point in the aggregate's lifecycle. The actual write is `EventSourcingStrategy`'s job (Phase 8).

- [ ] **Step 1: TDD `SnapshotStrategy` interface**

```php
interface SnapshotStrategy
{
    public function shouldSnapshot(EventSourcedAggregateRoot $aggregate, int $eventCountSinceLastSnapshot): bool;
}
```

- [ ] **Step 2: TDD `NeverSnapshot`** — always returns false. Tests: assert false for any aggregate / any count.

- [ ] **Step 3: TDD `EveryNEvents(int $n)`**
   - Test: `n=10`, count=5 → false
   - Test: `n=10`, count=10 → true
   - Test: `n=10`, count=20 → true
   - Edge: `n=0` should throw at construction (invalid)

- [ ] **Step 4: TDD `OnPredicate(callable $predicate)`** — delegates to the closure. `$predicate` receives `(EventSourcedAggregateRoot, int): bool`.

- [ ] **Step 5: Run tests + Psalm + PHPCS clean**
- [ ] **Step 6: Commit**

```bash
git commit -m "feat(ddd-aggregate): SnapshotStrategy + NeverSnapshot + EveryNEvents + OnPredicate"
```

---

## Phase 8 — `EventSourcedPersister` + `InMemoryEventSourcingStrategy`

**This is the highest-risk phase per the review.** Five collaborators: `VersionedEventStore`, `SnapshotStore`, `UpcasterPipeline`, `StreamStrategy`, `EventSourcedAggregateRootAccessor`. The expectedVersion arithmetic is the most error-prone piece.

**Files:**
- Create: `packages/nexus-ddd-aggregate/src/Strategy/EventSourcedPersister.php`
- Create: `packages/nexus-ddd-aggregate/src/Strategy/EventSourcing/EventSourcingStrategy.php`
- Create: `packages/nexus-ddd-aggregate/src/Strategy/EventSourcing/InMemoryEventSourcingStrategy.php` (convenience: composes in-memory pieces)
- Tests

### Behavioral contract (per v6 §9.2 + §10.3.1 + §25.6.4)

`load(class-string, Identifier): Option<EventSourceable>`:
1. Resolve `PersistenceId` from `(aggregateClass, identifier->value())`.
2. Try `SnapshotStore::loadLatest(persistenceId)`.
3. If snapshot exists AND `snapshot.stateVersion === currentStateVersion`: rehydrate aggregate from snapshot; replay events from `(snapshot.version + 1, currentVersion)` via `EventSourcedAggregateRootAccessor::replayOn`.
4. If snapshot exists BUT `stateVersion` mismatches: **fall back to full replay from event 1**. Log `ddd.snapshot.incompatible_fallback` metric. Do NOT throw `SnapshotIncompatibleException`.
5. If no snapshot: full replay from event 1.
6. If no events AND no snapshot: return `Option::none()`.
7. After load, the aggregate's internal version field is set to the count of replayed events via `EventSourcedAggregateRootAccessor::rehydrateVersionOn`.

`persist(EventSourceable)`:
1. Resolve `PersistenceId` from `(aggregateClass, aggregate->id()->value())`.
2. Pull recorded events: `$events = $accessor->popRecordedEventsFrom($aggregate)`.
3. If `$events === []`: return (idempotent no-op).
4. **Compute `expectedVersion` = `$accessor->extractVersion($aggregate) - count($events)`**. (After `recordThat` calls, `version()` has already advanced; subtract the count to reach the version the aggregate had *before* the new events.)
5. Convert each event to `EventEnvelope`, sequence numbers `(expectedVersion + 1) .. (expectedVersion + count($events))`.
6. `$store->appendIfVersion($persistenceId, $expectedVersion, ...$envelopes)` — this raises `OptimisticLockException` (version mismatch) or `AggregateAlreadyExistsException` (uniqueness violation when `expectedVersion === 0`).
7. **Snapshot decision happens AFTER append succeeds, in a separate transaction.** Consult `$snapshotStrategy->shouldSnapshot($aggregate, $eventCountSinceLastSnapshot)`; if true, write snapshot. Snapshot-write failure is logged + metric, NOT raised.

- [ ] **Step 1: TDD `EventSourcedPersister` interface**

```php
interface EventSourcedPersister
{
    /**
     * @template T of EventSourcedAggregateRoot
     * @param class-string<T> $entityClass
     * @return Option<T>
     */
    public function load(string $entityClass, Identifier $id): Option;

    /**
     * @throws OptimisticLockException
     * @throws AggregateAlreadyExistsException
     */
    public function persist(EventSourceable $entity): void;
}
```

- [ ] **Step 2: TDD `EventSourcingStrategy` — happy-path persist**

```php
#[Test]
public function persistAppendsRecordedEventsAtCorrectExpectedVersion(): void
{
    $store = new InMemoryVersionedEventStore();
    $strategy = $this->buildStrategy($store);
    $order = Order::placeNew(OrderId::generate(), CustomerId::generate(), new OrderLines([]));

    $strategy->persist($order);

    self::assertSame(1, $store->highestSequenceNr(PersistenceId::of(Order::class, $order->id()->value())));
}
```

- [ ] **Step 3: TDD persist after load — expectedVersion math**

```php
#[Test]
public function persistAfterLoadComputesExpectedVersionAsAggregateVersionMinusEventCount(): void
{
    $store = new InMemoryVersionedEventStore();
    $strategy = $this->buildStrategy($store);
    $orderId = OrderId::generate();
    $order = Order::placeNew($orderId, CustomerId::generate(), new OrderLines([]));
    $strategy->persist($order);

    /** @var Order $reloaded */
    $reloaded = $strategy->load(Order::class, $orderId)->get();
    self::assertSame(1, $accessor->extractVersion($reloaded));

    $reloaded->addLine(new OrderLine(/* ... */));   // recordThat → version 2
    $strategy->persist($reloaded);

    self::assertSame(2, $store->highestSequenceNr(PersistenceId::of(Order::class, $orderId->value())));
}
```

- [ ] **Step 4: TDD persist OCC mismatch raises `OptimisticLockException`**

```php
#[Test]
public function persistRaisesOptimisticLockOnConcurrentMutation(): void
{
    $store = new InMemoryVersionedEventStore();
    $strategy = $this->buildStrategy($store);
    $orderId = OrderId::generate();

    // Writer A loads.
    $strategy->persist(Order::placeNew($orderId, CustomerId::generate(), new OrderLines([])));
    $a = $strategy->load(Order::class, $orderId)->get();
    $b = $strategy->load(Order::class, $orderId)->get();

    $a->addLine(new OrderLine(/* ... */));
    $strategy->persist($a);   // succeeds

    $b->addLine(new OrderLine(/* ... */));
    $this->expectException(OptimisticLockException::class);
    $strategy->persist($b);   // fails — store is at version 2, $b's expectedVersion is 1
}
```

- [ ] **Step 5: TDD persist v=0 collision raises `AggregateAlreadyExistsException`**

```php
#[Test]
public function persistOfNewAggregateWithExistingIdRaisesAggregateAlreadyExists(): void
{
    $store = new InMemoryVersionedEventStore();
    $strategy = $this->buildStrategy($store);
    $orderId = OrderId::generate();

    $strategy->persist(Order::placeNew($orderId, CustomerId::generate(), new OrderLines([])));

    $this->expectException(AggregateAlreadyExistsException::class);
    $strategy->persist(Order::placeNew($orderId, CustomerId::generate(), new OrderLines([])));
    // Both have expectedVersion=0 (brand-new); second collides on UNIQUE.
}
```

The strategy's persist method MUST distinguish `expectedVersion === 0` from `expectedVersion > 0` when `appendIfVersion` raises `OptimisticLockException`, and re-throw as `AggregateAlreadyExistsException` for the v=0 case.

- [ ] **Step 6: TDD load returns `Option::none()` when no events + no snapshot**
- [ ] **Step 7: TDD load + replay reconstructs aggregate state**
- [ ] **Step 8: TDD load with compatible snapshot uses snapshot + tail events**
- [ ] **Step 9: TDD load with incompatible snapshot falls back to full replay (per v6 §10.3.1)**

```php
#[Test]
public function loadFallsBackToFullReplayOnSnapshotIncompatibility(): void
{
    // Persist v1 snapshot with stateVersion=1, then the aggregate's stateVersion bumps to 2.
    // Loading should ignore the snapshot and replay from event 1.
    // Test verifies: loaded aggregate's state matches event-1-replay state, NOT snapshot state.
    // No exception is thrown.
}
```

- [ ] **Step 10: TDD snapshot-write happens AFTER persist commit, snapshot-write failure is non-fatal**

```php
#[Test]
public function snapshotWriteFailureDoesNotRollBackPersist(): void
{
    $store = new InMemoryVersionedEventStore();
    $snapshotStore = new FailingSnapshotStore();   // throws on save()
    $strategy = $this->buildStrategy($store, $snapshotStore, snapshotStrategy: new EveryNEvents(1));
    $order = Order::placeNew(OrderId::generate(), CustomerId::generate(), new OrderLines([]));

    $strategy->persist($order);   // Snapshot save fails internally; persist succeeds.

    self::assertSame(1, $store->highestSequenceNr(PersistenceId::of(Order::class, $order->id()->value())));
    // (Optionally assert a metric was recorded.)
}
```

- [ ] **Step 11: Implement `EventSourcingStrategy`**

```php
final class EventSourcingStrategy implements EventSourcedPersister
{
    public function __construct(
        private readonly VersionedEventStore $store,
        private readonly SnapshotStore $snapshots,
        private readonly UpcasterPipeline $upcasters,
        private readonly StreamStrategy $streamStrategy,
        private readonly SnapshotStrategy $snapshotStrategy,
        private readonly LoggerInterface $logger,
    ) {}

    public function load(string $entityClass, Identifier $id): Option { /* see contract above */ }

    public function persist(EventSourceable $entity): void
    {
        $accessor = new EventSourcedAggregateRootAccessor();
        $events = $accessor->popRecordedEventsFrom($entity);
        if ($events === []) return;

        $persistenceId = PersistenceId::of($entity::class, $entity->id()->value());
        $aggregateVersion = $accessor->extractVersion($entity);
        $expectedVersion = $aggregateVersion - count($events);

        $envelopes = [];
        $seq = $expectedVersion + 1;
        foreach ($events as $event) {
            $envelopes[] = EventEnvelope::of($persistenceId, $seq++, $event, $now /* ... */);
        }

        try {
            $this->store->appendIfVersion($persistenceId, $expectedVersion, ...$envelopes);
        } catch (OptimisticLockException $e) {
            if ($expectedVersion === 0) {
                throw AggregateAlreadyExistsException::for($entity::class, $entity->id()->value());
            }
            throw $e;
        }

        // Snapshot decision happens post-commit, separate concern.
        if ($this->snapshotStrategy->shouldSnapshot($entity, /* eventCountSinceLastSnapshot */)) {
            try {
                $this->snapshots->save(/* snapshot */);
            } catch (Throwable $e) {
                $this->logger->warning('snapshot write failed; persist already committed', ['exception' => $e]);
                // metric: ddd.snapshot.write_failure
            }
        }
    }
}
```

- [ ] **Step 12: Implement `InMemoryEventSourcingStrategy`** — convenience constructor that wires `InMemoryVersionedEventStore` + `InMemorySnapshotStore` + the supplied `UpcasterPipeline` + `SingleStreamStrategy` + `NeverSnapshot` + `NullLogger`.

- [ ] **Step 13: All tests green; Psalm + PHPCS clean**
- [ ] **Step 14: Commit**

```bash
git commit -m "feat(ddd-aggregate): EventSourcedPersister + EventSourcingStrategy + InMemoryEventSourcingStrategy (OCC + snapshot fallback + post-commit-snapshot timing)"
```

---

## Phase 9 — `StatefulPersister` + `InMemoryStatefulStrategy`

**Files:**
- Create: `packages/nexus-ddd-aggregate/src/Strategy/StatefulPersister.php`
- Create: `packages/nexus-ddd-aggregate/src/Strategy/Stateful/InMemoryStatefulStrategy.php`
- Tests

`StatefulPersister::load(class-string, Identifier): Option<StatefulAggregateRoot>` — loads serialized state; returns `Option::none()` on miss.
`StatefulPersister::persist(StatefulAggregateRoot)` — for v=0 aggregates → INSERT; for v>0 → UPDATE WHERE version=? Mismatch → `OptimisticLockException`; uniqueness violation on INSERT → `AggregateAlreadyExistsException`.

For the in-memory impl, the "state store" is an associative array keyed on `(class, id)` storing `(state-array, version)` tuples.

- [ ] **Step 1: TDD `StatefulPersister` interface**
- [ ] **Step 2: TDD happy-path persist + load round-trip**
- [ ] **Step 3: TDD OCC mismatch on existing aggregate raises `OptimisticLockException`**
- [ ] **Step 4: TDD uniqueness collision on new aggregate raises `AggregateAlreadyExistsException`**
- [ ] **Step 5: Implement `InMemoryStatefulStrategy`**
- [ ] **Step 6: Run tests + Psalm + PHPCS**
- [ ] **Step 7: Commit**

```bash
git commit -m "feat(ddd-aggregate): StatefulPersister + InMemoryStatefulStrategy"
```

---

## Phase 10 — `PersistenceStrategy` + `CompositePersistenceStrategy`

**Files:**
- Create: `packages/nexus-ddd-aggregate/src/Strategy/PersistenceStrategy.php`
- Create: `packages/nexus-ddd-aggregate/src/Strategy/CompositePersistenceStrategy.php`
- Tests

Public `PersistenceStrategy` is the single seam the Repository sees. `CompositePersistenceStrategy` dispatches by aggregate kind once at the entry seam — no `instanceof` branching elsewhere.

- [ ] **Step 1: TDD `PersistenceStrategy` interface**

```php
interface PersistenceStrategy
{
    /**
     * @template T of EventSourceable
     * @param class-string<T> $entityClass
     * @return Option<T>
     */
    public function load(string $entityClass, Identifier $id): Option;

    /**
     * @throws OptimisticLockException
     * @throws AggregateAlreadyExistsException
     */
    public function persist(EventSourceable $entity): void;
}
```

- [ ] **Step 2: TDD `CompositePersistenceStrategy` dispatches stateful aggregates to `StatefulPersister`**
- [ ] **Step 3: TDD `CompositePersistenceStrategy` dispatches everything else to `EventSourcedPersister`** (default branch)
- [ ] **Step 4: Implement**

```php
final readonly class CompositePersistenceStrategy implements PersistenceStrategy
{
    public function __construct(
        private EventSourcedPersister $eventSourced,
        private StatefulPersister $stateful,
    ) {}

    public function persist(EventSourceable $entity): void
    {
        match (true) {
            $entity instanceof StatefulAggregateRoot => $this->stateful->persist($entity),
            default                                  => $this->eventSourced->persist($entity),
        };
    }

    public function load(string $entityClass, Identifier $id): Option
    {
        if (is_subclass_of($entityClass, StatefulAggregateRoot::class)) {
            return $this->stateful->load($entityClass, $id);
        }
        return $this->eventSourced->load($entityClass, $id);
    }
}
```

- [ ] **Step 5: Run tests + Psalm + PHPCS clean**
- [ ] **Step 6: Commit**

```bash
git commit -m "feat(ddd-aggregate): PersistenceStrategy + CompositePersistenceStrategy"
```

---

## Phase 11 — `AggregateRepository` + `GenericAggregateRepository`

**Files:**
- Create: `packages/nexus-ddd-aggregate/src/Repository/AggregateRepository.php`
- Create: `packages/nexus-ddd-aggregate/src/Repository/GenericAggregateRepository.php`
- Tests

Three methods (per v6 §9.1): `find()` / `add()` / `save()`. Mapping:
- `find(Identifier): Option<T>` → `strategy->load($aggregateClass, $id)` with **Psalm narrowing** — `Option<EventSourceable>` is narrowed to `Option<T>` because `class-string<T>` was passed in.
- `add(AggregateRoot)` → asserts `version === 0`, delegates to `strategy->persist()`. Strategy classifies UNIQUE-collision as `AggregateAlreadyExistsException`.
- `save(AggregateRoot)` → upsert. v=0 behaves like `add()`; v>0 versioned-append; mismatch → `OptimisticLockException`.

**MultiAggregateTransactionException is NOT thrown by this class.** The one-aggregate-per-transaction rule (per v6 §9.1.0.2) is enforced by **bus middleware** in a future package, NOT by `GenericAggregateRepository`. The exception class lives in this package (Phase 2) so it's available for the bus middleware to import — but no method here throws it. Tests should verify the absence: a second `save()` with a different aggregate succeeds at the Repository level (the repo doesn't know about transaction state).

- [ ] **Step 1: TDD `AggregateRepository` interface signature test (Reflection-based)**

- [ ] **Step 2: Write `AggregateRepository` interface**

```php
namespace Monadial\Nexus\Ddd\Aggregate\Repository;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Aggregate\Exception\AggregateAlreadyExistsException;
use Monadial\Nexus\Ddd\Core\Aggregate\AggregateRoot;
use Monadial\Nexus\Ddd\Core\Exception\OptimisticLockException;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;

/** @template T of AggregateRoot */
interface AggregateRepository
{
    /** @return Option<T> */
    public function find(Identifier $id): Option;

    /**
     * @param T $aggregate
     * @throws AggregateAlreadyExistsException
     */
    public function add(AggregateRoot $aggregate): void;

    /**
     * @param T $aggregate
     * @throws OptimisticLockException
     * @throws AggregateAlreadyExistsException
     */
    public function save(AggregateRoot $aggregate): void;
}
```

- [ ] **Step 3: TDD `GenericAggregateRepository` find returns Option::none() on miss**
- [ ] **Step 4: TDD `find()` returns the loaded aggregate on hit**
- [ ] **Step 5: TDD `add()` of a fresh aggregate succeeds**
- [ ] **Step 6: TDD second `add()` of the same id throws `AggregateAlreadyExistsException`**
- [ ] **Step 7: TDD `save()` of a v=0 aggregate is equivalent to `add()`** — uniqueness check applies; collision raises `AggregateAlreadyExistsException`
- [ ] **Step 8: TDD `save()` of a loaded aggregate that has been concurrently mutated raises `OptimisticLockException`**
- [ ] **Step 9: TDD `add()` of a loaded aggregate (v>0) is rejected** — programmer error; throws (raise a focused exception or LogicException)

- [ ] **Step 10: Implement**

```php
/**
 * @template T of AggregateRoot
 * @implements AggregateRepository<T>
 */
final readonly class GenericAggregateRepository implements AggregateRepository
{
    /** @param class-string<T> $aggregateClass */
    public function __construct(
        private string $aggregateClass,
        private PersistenceStrategy $strategy,
    ) {}

    public function find(Identifier $id): Option
    {
        // Psalm narrowing: PersistenceStrategy::load returns Option<EventSourceable>.
        // Because we pass class-string<T> and T extends EventSourceable, the narrow
        // is sound. The /** @var Option<T> */ docblock pins the type for Psalm.
        /** @var Option<T> $loaded */
        $loaded = $this->strategy->load($this->aggregateClass, $id);
        return $loaded;
    }

    public function add(AggregateRoot $aggregate): void
    {
        if ($aggregate->version() !== 0) {
            throw new \LogicException(sprintf(
                'add() invoked on an aggregate with version %d; add() requires version 0. Use save() for previously-loaded aggregates.',
                $aggregate->version(),
            ));
        }
        $this->strategy->persist($aggregate);
    }

    public function save(AggregateRoot $aggregate): void
    {
        $this->strategy->persist($aggregate);
    }
}
```

- [ ] **Step 11: Run tests + Psalm + PHPCS clean**
- [ ] **Step 12: Commit**

```bash
git commit -m "feat(ddd-aggregate): AggregateRepository + GenericAggregateRepository (find/add/save)"
```

---

## Phase 12 — Smoke tests (single-apply + match pattern)

**Files:**
- Create: `packages/nexus-ddd-aggregate/tests/Unit/Smoke/Fixtures/Order.php` (fixture aggregate using shipped `EventSourcedAggregateRoot` single-apply pattern)
- Create: `packages/nexus-ddd-aggregate/tests/Unit/Smoke/Fixtures/OrderId.php`
- Create: `packages/nexus-ddd-aggregate/tests/Unit/Smoke/Fixtures/CustomerId.php`
- Create: `packages/nexus-ddd-aggregate/tests/Unit/Smoke/Fixtures/OrderLines.php`
- Create: `packages/nexus-ddd-aggregate/tests/Unit/Smoke/Fixtures/OrderLine.php`
- Create: `packages/nexus-ddd-aggregate/tests/Unit/Smoke/Fixtures/OrderPlaced.php`
- Create: `packages/nexus-ddd-aggregate/tests/Unit/Smoke/Fixtures/OrderLineAdded.php`
- Create: `packages/nexus-ddd-aggregate/tests/Unit/Smoke/PlaceOrderSmokeTest.php`
- Create: `packages/nexus-ddd-aggregate/tests/Unit/Smoke/EventSourcedReplaySmokeTest.php`
- Create: `packages/nexus-ddd-aggregate/tests/Unit/Smoke/OptimisticLockSmokeTest.php`
- Create: `packages/nexus-ddd-aggregate/tests/Unit/Smoke/SnapshotIncompatibilityFallbackSmokeTest.php`

**Fixture aggregate uses the SHIPPED single-`apply()` + `match (true)` pattern (NOT per-event method names):**

```php
namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Smoke\Fixtures;

use Monadial\Nexus\Ddd\Core\Aggregate\EventSourcedAggregateRoot;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use Override;

/**
 * @extends EventSourcedAggregateRoot<OrderId, OrderPlaced|OrderLineAdded>
 */
final class Order extends EventSourcedAggregateRoot
{
    private CustomerId $customer;
    private OrderLines $lines;

    private function __construct(OrderId $id) { parent::__construct($id); }

    #[Override]
    public function id(): OrderId
    {
        /** @var OrderId */
        return parent::id();
    }

    public static function placeNew(OrderId $id, CustomerId $customer, OrderLines $lines): self
    {
        $order = new self($id);
        $order->recordThat(new OrderPlaced($id, $customer, $lines));
        return $order;
    }

    public function addLine(OrderLine $line): void
    {
        $this->recordThat(new OrderLineAdded($this->id(), $line));
    }

    #[Override]
    protected function apply(DomainEvent $event): void
    {
        match (true) {
            $event instanceof OrderPlaced     => $this->whenPlaced($event),
            $event instanceof OrderLineAdded  => $this->whenLineAdded($event),
            default                           => throw new \LogicException(sprintf('Unexpected event %s for Order aggregate.', $event::class)),
        };
    }

    private function whenPlaced(OrderPlaced $e): void
    {
        $this->customer = $e->customerId;
        $this->lines = $e->lines;
    }

    private function whenLineAdded(OrderLineAdded $e): void
    {
        $this->lines = $this->lines->withAdded($e->line);
    }
}
```

The `apply()` method uses `match (true) { $event instanceof X => ... }`. Per-event helper methods (`whenPlaced`, `whenLineAdded`) are private — names are *not* load-bearing (no reflection-based dispatch); they're just for organization.

- [ ] **Step 1: Write fixture types** (Order, OrderId, CustomerId, OrderLines, OrderLine, OrderPlaced, OrderLineAdded). All `final readonly class` for events; OrderLines is a value object collection.

- [ ] **Step 2: TDD `PlaceOrderSmokeTest`** — uses InMemoryEventSourcingStrategy + GenericAggregateRepository; `add()` an Order; `find()` it back; assert state matches.

- [ ] **Step 3: TDD `EventSourcedReplaySmokeTest`** — persist an Order with multiple events; reload; assert reconstructed state matches the post-replay state.

- [ ] **Step 4: TDD `OptimisticLockSmokeTest`** — concurrent-writer simulation: two repository instances over the same store; both load; both modify; first save() succeeds; second raises `OptimisticLockException`.

- [ ] **Step 5: TDD `SnapshotIncompatibilityFallbackSmokeTest`** — write a snapshot at stateVersion=1; bump fixture's `stateVersion()` to 2; reload — assert no exception thrown, state derived from full replay (verify via a state-marker that differentiates snapshot-rehydration from event-replay paths, e.g., a counter incremented in `apply()`).

- [ ] **Step 6: Run all smoke tests; Psalm + PHPCS clean**
- [ ] **Step 7: Commit**

```bash
git commit -m "test(ddd-aggregate): smoke tests for placeOrder + replay + OCC + snapshot-incompatibility-fallback"
```

---

## Phase 13 — Psalm rules in `nexus-psalm`

**Files (in `nexus-psalm` package, NOT in `nexus-ddd-aggregate`):**
- Create: `packages/nexus-psalm/src/Hook/Aggregate/FactoryAssignsOnlyIdRule.php`
- Create: `packages/nexus-psalm/src/Hook/Aggregate/AggregateEmitsOnlyEventsRule.php`
- Create: `packages/nexus-psalm/src/Hook/Aggregate/AggregateRepositoryReadOnlyBulkRule.php`
- Modify: `packages/nexus-psalm/src/Hook/ReplaySafeApplyRule.php` — strengthen forbidden-ops list AND extend class targets to `Upcaster::upcast` + `SnapshotUpcaster::upcast` (per v6 §6.4 + §10.2)
- Create: `packages/nexus-psalm/src/Hook/Aggregate/EventHandlerInboxTransactionRule.php` (best-effort heuristic — flags `EventListener` impls touching DB without sharing inbox connection)
- Create corresponding Issue classes
- Create fixtures + tests for each (~5 rules × 3 files = ~15 new files)
- Modify: `packages/nexus-psalm/src/Plugin.php` to register the new rules

**B1 alignment:** `ReplaySafeApplyRule` targets the `apply(DomainEvent $event): void` method on `EventSourcedAggregateRoot` subclasses (single method, NOT `applyXxx` per-event names). It also targets `Upcaster::upcast` and `SnapshotUpcaster::upcast` — same forbidden-ops list (clock, RNG, logger, container, ambient runtime state, I/O, `recordThat`, bus calls).

- [ ] **Step 1: TDD-then-implement `FactoryAssignsOnlyIdRule`**

The rule fires on aggregate static factories (any public/private static method on an `AggregateRoot` subclass). Forbidden inside the body: any direct `$this->prop = value` other than `$this->id = $id` in the constructor. Allowed: calling `recordThat()` (which routes through `apply()` to do the assignments).

Fixture: a `BadFactoryAssignsCustomerDirectly` aggregate where `placeNew` does `$order->customer = $cmd->customer;` instead of `recordThat(new OrderPlaced(...))`. Test asserts the rule fires.

- [ ] **Step 2: TDD-then-implement `AggregateEmitsOnlyEventsRule`**

The rule fires on classes that extend `AggregateRoot`. Forbidden inside any method body: any call to a `Bus` interface (`CommandBus`, `EventBus`, `QueryBus`) or `tell()` / `dispatchCommand()` / `publishEvent()` / `dispatchEnveloped()` / `publishEnveloped()`. The aggregate may only `recordThat()` events; cross-aggregate flow goes through process managers.

- [ ] **Step 3: TDD-then-implement `AggregateRepositoryReadOnlyBulkRule`**

The rule fires on classes that extend `GenericAggregateRepository`. Any non-`#[BulkCommand]`-attributed method that returns `iterable<T>` / `array<T>` is flagged — that's a query in disguise; belongs in `QueryBus` + projection.

- [ ] **Step 4: Strengthen `ReplaySafeApplyRule`**

Existing rule forbids I/O, `recordThat`, `tell`. v6 spec adds: clock reads (DateTimeImmutable construction, time(), microtime(), ClockInterface calls), RNG (random_int, Ulid::generate), logger calls, container/service-locator access, ambient context reads (MessageContextStack::current()).

Rule targets:
- `EventSourcedAggregateRoot::apply` overrides
- `Upcaster::upcast` overrides
- `SnapshotUpcaster::upcast` overrides

- [ ] **Step 5: TDD-then-implement `EventHandlerInboxTransactionRule`** (best-effort heuristic — full enforcement requires deeper analysis; flag clear violations only)

- [ ] **Step 6: Register all 5 rules in `Plugin.php`**
- [ ] **Step 7: Run plugin testsuite; all rules pass**
- [ ] **Step 8: Commit each rule as its own commit (per existing Phase-5b convention)**

---

## Phase 14 — Fitness tests

**Files:**
- Create: `packages/nexus-ddd-aggregate/tests/Unit/Fitness/PackageDependencyFitnessTest.php`
- Create: `packages/nexus-ddd-aggregate/tests/Unit/Fitness/ForbiddenImportsFitnessTest.php`
- Create: `packages/nexus-ddd-aggregate/tests/Unit/Fitness/AbstractClassReadonlyOrFinalFitnessTest.php`

`PackageDependencyFitnessTest` walks `src/` and asserts no `use` statements outside `[fp4php, monadial/nexus-persistence, nexus-actors/ddd-core, psr/clock, internal aggregate package]`. Specifically forbids imports of `nexus-ddd-messaging` (markers belong there but aggregate doesn't import them).

`ForbiddenImportsFitnessTest` forbids: Symfony, Laravel, Doctrine ORM, Doctrine DBAL, Monolog, ReactPHP, Amphp.

`AbstractClassReadonlyOrFinalFitnessTest` verifies all classes in `src/` are either `abstract` or `final`.

- [ ] **Step 1–3: TDD each fitness test**
- [ ] **Step 4: Commit**

```bash
git commit -m "test(ddd-aggregate): fitness functions (package deps, forbidden imports, final-or-abstract)"
```

---

## Phase 15 — Documentation pass

**Files:**
- Create: `packages/nexus-ddd-aggregate/README.md`
- Verify: every public `interface` and `class` has a `@psalm-api` docblock with a 2-paragraph description.

The README must reference §25.6 known limitations from the umbrella spec — particularly §25.6.1 (causation-chain integrity across writer-id changes) and §25.6.4 (snapshot-vs-event-store transactional divergence). Adopters need to know what's deferred.

- [ ] **Step 1: Write README**
- [ ] **Step 2: Docblock sweep**
- [ ] **Step 3: Commit**

```bash
git commit -m "docs(ddd-aggregate): README + class docblock pass"
```

---

## Phase 16 — Final CI sweep + PR

- [ ] **Step 1: Full pipeline**

```bash
docker compose exec -T php composer dump-autoload --quiet
docker compose exec -T php vendor/bin/psalm --no-cache
docker compose exec -T php vendor/bin/phpunit --testsuite=unit
docker compose exec -T php vendor/bin/phpunit --testsuite=psalm
docker compose exec -T php vendor/bin/phpcs packages/nexus-ddd-aggregate/
docker compose exec -T php vendor/bin/php-cs-fixer fix --dry-run packages/nexus-ddd-aggregate/src
docker compose exec -T php vendor/bin/deptrac
```

All clean.

- [ ] **Step 2: Push branch + open PR**

```bash
git push -u origin feat/nexus-ddd-aggregate
gh pr create --title "feat(ddd): add nexus-ddd-aggregate package" --body "$(cat <<'BODY'
## Summary

Adds `nexus-ddd-aggregate` — the persistence layer for the Nexus DDD framework. In-memory-first; concrete DBAL/Doctrine adapters live in follow-up packages.

- AggregateRepository<T> + GenericAggregateRepository<T> (find/add/save) per v6 §9.1
- PersistenceStrategy + CompositePersistenceStrategy over EventSourcedPersister + StatefulPersister
- EventSourcingStrategy with snapshot integration + fall-back-to-replay on incompatibility
- VersionedEventStore interface + InMemoryVersionedEventStore (UNIQUE-on-version OCC primitive)
- StreamStrategy (SingleStreamStrategy default; PerAggregateTypeStreamStrategy opt-in)
- Upcaster + UpcasterPipeline (forward-only with upcastTo for projection rebuilds)
- 5 new Psalm rules: factory-only-sets-id; aggregate-emits-only-events; repo-readonly-bulk; replay-safe-apply (strengthened, extended to upcasters); inbox-transaction-heuristic
- 4 new exceptions: AggregateAlreadyExistsException (DomainException), MultiAggregateTransactionException + EventNameCollisionException + UpcasterChainGapException (NexusDddException)

Reuses already-shipped: OptimisticLockException (in nexus-ddd-core), EventStore/SnapshotStore/PersistenceId/EventEnvelope (in nexus-persistence), EventSourcedAggregateRoot + EventSourcedAggregateRootAccessor (in nexus-ddd-core).

## Test plan

- [x] make psalm — clean
- [x] make test-unit — N tests pass
- [x] phpcs/php-cs-fixer — clean
- [x] deptrac — no boundary violations
- [ ] Mutation testing (deferred to follow-up PR)
- [ ] Cross-package integration via nexus-ddd-bus (follow-up package)

## Notes

Spec reference: docs/superpowers/specs/2026-05-06-nexus-ddd-umbrella-design.md v6 §9 + §10 + §25.6.

## Known deferred items

- MultiAggregateTransactionException enforcement lives in bus-middleware (follow-up package).
- Consumer-side inbox transactional contract is messaging-/bus-side (follow-up package).
- TerminalFailure/TransientFailure marker interfaces live in nexus-ddd-messaging; aggregate-side exceptions deliberately do NOT implement them — bus middleware classifies retry behavior by exception type.
BODY
)"
```

---

## Self-review checklist

Before considering the plan complete, verify:

- [ ] Every public class in the file structure has a Phase that produces it.
- [ ] Every Phase has TDD ordering (test → fail → impl → pass → commit) with concrete code.
- [ ] Cross-references to v6 §9.x are correct.
- [ ] No type/method-name drift between phases (e.g., `add` in Phase 11 matches `AggregateRepository::add` in the docblock).
- [ ] Fixture aggregate uses single `apply(DomainEvent)` + `match (true)` (NOT `applyXxx` per-event names) — matches shipped `EventSourcedAggregateRoot`.
- [ ] `ReplaySafeApplyRule` targets `apply()` AND `Upcaster::upcast()` AND `SnapshotUpcaster::upcast()`.
- [ ] `MultiAggregateTransactionException` is created in Phase 2 but NOT thrown by the Repository — it's bus-middleware's job.
- [ ] `OptimisticLockException` is reused as-shipped (extends `DomainException`, no marker interface).
- [ ] In-memory `VersionedEventStore` impl shows ALL FOUR parent `EventStore` methods plus `appendIfVersion`.
- [ ] `expectedVersion` arithmetic in Phase 8 is `aggregate.version() - count(events)`.
- [ ] Snapshot writes happen post-commit per v6 §25.6.4.
- [ ] Snapshot incompatibility falls back to replay (no exception) per v6 §10.3.1.
- [ ] No `AggregateRepository::remove()` method anywhere.
- [ ] `StreamStrategy` interface has only `streamFor()` — no `tableFor()`.
- [ ] `ddd_event_index` reframed-as-projection is correctly out of scope (no phase produces it).

---

## Execution handoff

After this plan is reviewed (round 2):

**1. Subagent-Driven (recommended)** — fresh subagent per phase; two-stage review per phase (spec compliance, then code quality). Best for keeping context clean.

**2. Inline execution** — execute phases in this session via `superpowers:executing-plans`.

User picks one before kickoff.
