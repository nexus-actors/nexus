# UntypedActorRefInjection Psalm Rule Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Psalm plugin rule that flags every injected `ActorRef` (or subtype) whose declared type lacks a concrete message-type generic — bare `ActorRef` and `ActorRef<object>` both fail — plus the internal generify pass, config-based exclusions, and full docs/example updates.

**Architecture:** One new hook class in `nexus-psalm` implementing `AfterClassLikeAnalysisInterface` (properties, promoted params, and all method params via `$storage->methods` — this also covers bodyless interface methods) and `AfterFunctionLikeAnalysisInterface` (closures and plain functions only; `MethodStorage` instances are skipped there to avoid double-reporting). Detection walks type unions recursively (containers included). Excluded ref classes come from psalm.xml plugin config, with `DeadLetterRef` always excluded by default. Framework-internal by-design heterogeneous files are exempted via Psalm's built-in `issueHandlers` in the monorepo psalm.xml. Before the rule lands, a generify pass removes the `ActorRef<object>` uses that were just imprecise typing.

**Tech Stack:** PHP 8.5, Psalm 6 plugin API (`PluginIssue`, `IssueBuffer`, `ClassLikeStorage`, `FunctionLikeStorage`), PHPUnit 13, Docker (all commands via `docker compose exec -T php` or `make`).

**Spec:** `docs/superpowers/specs/2026-07-03-untyped-actorref-injection-psalm-rule-design.md`

## Global Constraints

- **All commands run through Docker** — `docker compose exec -T php …` or `make` targets. Never `php`/`composer`/`vendor/bin/*` on the host.
- **No `Co-Authored-By: Claude`** (or any attribution trailer) in commit messages.
- **Execute on the `feat/nexus-messenger` branch** (or a branch cut from it) — Tasks 2 and 3 modify `packages/nexus-messenger` files that exist only there.
- Code style: PER-CS2.0 + Slevomat. Blank line before `if`/`foreach`/etc. blocks; string-keyed arrays sorted alphabetically; multi-line ternaries; ordered imports (class, function, const — each alphabetical); trailing commas in multiline contexts; all classes `final`.
- GrumPHP pre-commit runs cs-fixer, phpcs, psalm, phpunit on every commit — every task must leave the monorepo green (`make psalm` passes) or its commit will be rejected.
- The plugin rule name / issue type is exactly `UntypedActorRefInjection`. The ActorRef FQCN is `Monadial\Nexus\Core\Actor\ActorRef`; the default-excluded ref is `Monadial\Nexus\Core\Actor\DeadLetterRef`.

## File Structure

| File | Responsibility |
|---|---|
| `packages/nexus-psalm/src/Issue/UntypedActorRefInjection.php` | New Psalm issue type (message text) |
| `packages/nexus-psalm/src/Hook/UntypedActorRefInjectionRule.php` | New rule: both event hooks + union walker + exclude list |
| `packages/nexus-psalm/src/Plugin.php` | Register rule; parse `<untypedActorRefInjection>` plugin config |
| `packages/nexus-psalm/tests/Fixture/UntypedActorRefFixture.php` | Fixture classes exercising fail/pass cases |
| `packages/nexus-psalm/tests/PluginTest.php` | Fixture-driven assertions (existing pattern) |
| `packages/nexus-psalm/tests/PluginConfigTest.php` | Unit test for config parsing |
| `packages/nexus-core/src/Actor/ActorContext.php`, `ActorCell.php`, `ActorSystem.php` | Bucket A generify (`watch`/`unwatch`/`stop` method templates) |
| `packages/nexus-messenger/src/Consumer/ReceiverActor.php`, `MessengerBridge.php` | Bucket B (`processedListener` → `ActorRef<MessagesProcessed>`) |
| `psalm.xml` | issueHandlers exemptions for by-design heterogeneous files |
| `website/docs/reference/psalm-rules.md`, `website/docs/packages/psalm.md`, `packages/nexus-psalm/README.md` | Rule documentation |
| ~9 docs pages + 2 example apps | Typed-injection annotation sweep |
| `CLAUDE.md` | Plugin hook list update |

---

### Task 1: Generify `watch`/`unwatch`/`stop` (Bucket A)

These methods only use the ref's identity (plus internal system-message sends), so they gain method-level templates instead of erasing callers' types to `object`.

**Files:**
- Modify: `packages/nexus-core/src/Actor/ActorContext.php` (interface docblocks for `stop` ~line 105, `watch` ~line 131, `unwatch` ~line 138)
- Modify: `packages/nexus-core/src/Actor/ActorCell.php` (implementations ~lines 389–424)
- Modify: `packages/nexus-core/src/Actor/ActorSystem.php` (`stop` ~line 200)

**Interfaces:**
- Consumes: existing `ActorRef<T>` interface (`@template T of object`), `PoisonPill`, `Watch`, `Unwatch` system messages.
- Produces: `ActorContext::watch/unwatch/stop` and `ActorSystem::stop` accept `ActorRef<W>` for any `W of object`. Task 6's issueHandlers list assumes these three methods no longer contain `ActorRef<object>` params.

- [ ] **Step 1: Update the `ActorContext` interface docblocks**

In `packages/nexus-core/src/Actor/ActorContext.php`, change the three docblocks (keep the existing prose lines, only change the `@param` and add `@template`):

```php
    /**
     * Gracefully stop a direct child actor.
     *
     * The child finishes processing its current message, then receives a
     * `PostStop` signal before being removed from the supervision tree.
     *
     * @template W of object
     * @param ActorRef<W> $child Reference to the child actor to stop
     */
    public function stop(ActorRef $child): void;
```

```php
    /**
     * Subscribe to termination notifications for `$target`.
     *
     * When `$target` stops, this actor receives a `Terminated` signal via its
     * `onSignal()` handler.
     *
     * @template W of object
     * @param ActorRef<W> $target Actor reference to watch for termination
     */
    public function watch(ActorRef $target): void;
```

```php
    /**
     * Cancel a previously registered death-watch subscription.
     *
     * @template W of object
     * @param ActorRef<W> $target Previously-watched reference to stop watching
     */
    public function unwatch(ActorRef $target): void;
```

- [ ] **Step 2: Update the `ActorCell` implementations**

In `packages/nexus-core/src/Actor/ActorCell.php` (~lines 389–424), mirror the templates. The bodies send system messages through the user-typed channel and store into the heterogeneous `$watchers` map, which Psalm will now flag — suppress with a rationale:

```php
    /**
     * @template W of object
     * @param ActorRef<W> $child
     */
    #[Override]
    public function stop(ActorRef $child): void
    {
        /** @psalm-suppress InvalidArgument — PoisonPill rides the user channel; the system-message path is type-erased by design. */
        $child->tell(new PoisonPill());
    }
```

```php
    /**
     * @template W of object
     * @param ActorRef<W> $target
     */
    #[Override]
    public function watch(ActorRef $target): void
    {
        /** @psalm-suppress InvalidArgument — Watch rides the user channel; the system-message path is type-erased by design. */
        $target->tell(new Watch($this->selfRef));
        /** @psalm-suppress InvalidPropertyAssignmentValue — watchers is a heterogeneous identity map. */
        $this->watchers[(string) $target->path()] = $target;
    }
```

```php
    /**
     * @template W of object
     * @param ActorRef<W> $target
     */
    #[Override]
    public function unwatch(ActorRef $target): void
    {
        /** @psalm-suppress InvalidArgument — Unwatch rides the user channel; the system-message path is type-erased by design. */
        $target->tell(new Unwatch($this->selfRef));
        unset($this->watchers[(string) $target->path()]);
    }
```

- [ ] **Step 3: Update `ActorSystem::stop`**

In `packages/nexus-core/src/Actor/ActorSystem.php` (~line 200):

```php
    /**
     * Gracefully stop an actor by sending it a PoisonPill.
     *
     * The actor processes all messages already in its mailbox before honouring
     * the PoisonPill, then delivers PostStop and stops its children. This method
     * returns immediately; the stop happens asynchronously.
     *
     * @template W of object
     * @param ActorRef<W> $ref The actor to stop.
     */
    public function stop(ActorRef $ref): void
    {
        /** @psalm-suppress InvalidArgument — PoisonPill rides the user channel; the system-message path is type-erased by design. */
        $ref->tell(new PoisonPill());
    }
```

- [ ] **Step 4: Run Psalm and adjust suppressions to what actually fires**

Run: `make psalm`

Psalm's exact issue names for the suppressed lines may differ (`InvalidArgument` vs `ArgumentTypeCoercion`, `InvalidPropertyAssignmentValue` vs `PropertyTypeCoercion`). If Psalm reports `UnusedPsalmSuppress` or a different issue type, swap the suppress to the reported type — keep the rationale comment text. Repeat until clean. Also fix any *caller* fallout inside core (callers passing `ActorRef<object>` children infer `W = object` and stay valid — none expected).

Expected: `make psalm` exits 0.

- [ ] **Step 5: Run the core unit tests**

Run: `docker compose exec -T php vendor/bin/phpunit packages/nexus-core/tests/Unit`
Expected: PASS (docblock-only change — no runtime behavior).

- [ ] **Step 6: Run style checks and commit**

```bash
make cs-fix && make phpcs
git add packages/nexus-core/src/Actor/ActorContext.php packages/nexus-core/src/Actor/ActorCell.php packages/nexus-core/src/Actor/ActorSystem.php
git commit -m "refactor(core): method-level generics for watch/unwatch/stop — no more ActorRef<object> erasure at call sites"
```

---

### Task 2: Type `processedListener` as `ActorRef<MessagesProcessed>` (Bucket B)

**Files:**
- Modify: `packages/nexus-messenger/src/Consumer/ReceiverActor.php:46` (`create()` docblock)
- Modify: `packages/nexus-messenger/src/MessengerBridge.php:66,90` (`receiverProps()` and `spawnReceivers()` docblocks)

**Interfaces:**
- Consumes: `Monadial\Nexus\Messenger\Lifecycle\MessagesProcessed` (already imported in ReceiverActor).
- Produces: `processedListener` params typed `ActorRef<MessagesProcessed>|null` — Task 6 assumes `ActorRef<object>` remains in these files only for `$deadLetters` and the router registries.

- [ ] **Step 1: Update `ReceiverActor::create()` docblock**

Change line 46 from:

```php
     * @param ActorRef<object>|null $processedListener receives MessagesProcessed reports (e.g. the LifecycleWatchdog)
```

to:

```php
     * @param ActorRef<MessagesProcessed>|null $processedListener receives MessagesProcessed reports (e.g. the LifecycleWatchdog)
```

- [ ] **Step 2: Update `MessengerBridge` docblocks**

In both `receiverProps()` (~line 66) and `spawnReceivers()` (~line 90), change:

```php
     * @param ActorRef<object>|null $processedListener
```

to:

```php
     * @param ActorRef<MessagesProcessed>|null $processedListener
```

Add the import to `MessengerBridge.php` (alphabetical position within class imports):

```php
use Monadial\Nexus\Messenger\Lifecycle\MessagesProcessed;
```

- [ ] **Step 3: Verify Psalm and messenger unit tests**

Run: `make psalm`
Expected: exit 0. `$processedListener->tell(new MessagesProcessed($processed))` in ReceiverActor now typechecks against the narrower generic.

Run: `docker compose exec -T php vendor/bin/phpunit packages/nexus-messenger/tests/Unit`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
make cs-fix && make phpcs
git add packages/nexus-messenger/src/Consumer/ReceiverActor.php packages/nexus-messenger/src/MessengerBridge.php
git commit -m "refactor(messenger): type processedListener as ActorRef<MessagesProcessed>"
```

---

### Task 3: Issue class + rule core (params) + registration + issueHandlers exemptions

The rule lands "armed" in the same commit as the psalm.xml exemptions so the monorepo stays green.

**Files:**
- Create: `packages/nexus-psalm/src/Issue/UntypedActorRefInjection.php`
- Create: `packages/nexus-psalm/src/Hook/UntypedActorRefInjectionRule.php`
- Create: `packages/nexus-psalm/tests/Fixture/UntypedActorRefFixture.php`
- Modify: `packages/nexus-psalm/src/Plugin.php` (hooks array)
- Modify: `packages/nexus-psalm/tests/PluginTest.php` (new tests)
- Modify: `psalm.xml` (issueHandlers block)

**Interfaces:**
- Consumes: Psalm APIs — `AfterClassLikeAnalysisEvent::getClasslikeStorage()/getCodebase()/getStatementsSource()`, `AfterFunctionLikeAnalysisEvent::getFunctionlikeStorage()/getCodebase()/getStatementsSource()`, `FunctionLikeParameter{name, type, location, type_location, promoted_property}`, `PropertyStorage{type, is_static, is_promoted, location, stmt_location, type_location, suppressed_issues}`, `MethodStorage extends FunctionLikeStorage`, `Codebase::{classExists, interfaceExists, classImplements, interfaceExtends, classExtends}`, atomics `TNamedObject{value}`, `TGenericObject{type_params}`, `TObject`, `TArray{type_params}`, `TIterable{type_params}`, `TKeyedArray{properties, fallback_params}`.
- Produces: `UntypedActorRefInjectionRule` with public static methods `configure(array $additionalExcludedRefs): void` and `excludedRefs(): array` (Task 5 wires config into them; the class initializes its exclude list to `[DeadLetterRef::class]` so defaults work without `configure()`). Issue type name `UntypedActorRefInjection` (Task 6+ docs reference it).

- [ ] **Step 1: Write the fixture (failing-test input)**

Create `packages/nexus-psalm/tests/Fixture/UntypedActorRefFixture.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Tests\Fixture;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\LocalActorRef;

final readonly class UarCommand {}

/** Bad: bare ActorRef param on a regular method. */
final class UarBareParamService
{
    public function setSink(ActorRef $sink): bool
    {
        return $sink->isAlive();
    }
}

/** Bad: explicit ActorRef<object> param. */
final class UarObjectParamService
{
    /** @param ActorRef<object> $orders */
    public function route(ActorRef $orders): bool
    {
        return $orders->isAlive();
    }
}

/** Bad: bypass attempt via concrete subtype. */
final class UarSubtypeBypassService
{
    public function connect(LocalActorRef $orders): bool
    {
        return $orders->isAlive();
    }
}

/** Bad: bare ActorRef closure param. */
final class UarClosureHost
{
    public function run(): bool
    {
        $probe = static fn(ActorRef $target): bool => $target->isAlive();

        return $probe !== null;
    }
}

/** Good: concrete message type. */
final class UarTypedParamService
{
    /** @param ActorRef<UarCommand> $orders */
    public function route(ActorRef $orders): bool
    {
        return $orders->isAlive();
    }
}

/**
 * Good: class-level template flows through.
 *
 * @template T of object
 */
final class UarTemplatedService
{
    /** @param ActorRef<T> $ref */
    public function forward(ActorRef $ref): bool
    {
        return $ref->isAlive();
    }
}

/** Good: nullable with typed generic. */
final class UarNullableTypedService
{
    /** @param ActorRef<UarCommand>|null $maybe */
    public function poke(?ActorRef $maybe): bool
    {
        return $maybe?->isAlive() ?? false;
    }
}

/** Good: suppression escape hatch. */
final class UarSuppressedService
{
    /** @psalm-suppress UntypedActorRefInjection */
    public function legacy(ActorRef $anything): bool
    {
        return $anything->isAlive();
    }
}

/** Good: non-ActorRef params are ignored. */
final class UarUnrelatedService
{
    public function greet(string $name): string
    {
        return 'hello ' . $name;
    }
}

/** Containers: bare and object-erased refs are flagged, typed pass. */
final class UarContainerService
{
    /** @param array<string, ActorRef> $bare */
    public function setBare(array $bare): int
    {
        return count($bare);
    }

    /** @param list<ActorRef<object>> $erased */
    public function setErased(array $erased): int
    {
        return count($erased);
    }

    /** @param array<class-string, ActorRef<UarCommand>> $typed */
    public function setTyped(array $typed): int
    {
        return count($typed);
    }
}
```

(add `use function count;` after the class imports, per the ordered-imports style)

- [ ] **Step 2: Add PluginTest cases and run them RED**

Append to `packages/nexus-psalm/tests/PluginTest.php` (before the private helpers):

```php
    #[Test]
    public function untypedActorRefRuleFlagsBareAndObjectParams(): void
    {
        $output = $this->runPsalmOnFixture('UntypedActorRefFixture.php');
        $lines = $this->filterIssueLines($output, 'UntypedActorRefInjection');

        // 6 issues: UarBareParamService::setSink, UarObjectParamService::route,
        // UarSubtypeBypassService::connect, UarClosureHost closure param,
        // UarContainerService::setBare, UarContainerService::setErased
        self::assertCount(6, $lines, "Expected 6 UntypedActorRefInjection issues:\n" . implode("\n", $lines));
        self::assertStringContains('setSink', $output);
        self::assertStringContains('UarObjectParamService', $output);
        self::assertStringContains('UarSubtypeBypassService', $output);
        self::assertStringContains('setBare', $output);
        self::assertStringContains('setErased', $output);
        self::assertStringNotContains('setTyped', $output);
    }

    #[Test]
    public function untypedActorRefRuleAllowsTypedTemplatedSuppressedAndUnrelated(): void
    {
        $output = $this->runPsalmOnFixture('UntypedActorRefFixture.php');
        $lines = $this->filterIssueLines($output, 'UntypedActorRefInjection');

        foreach ($lines as $line) {
            foreach (['UarTypedParamService', 'UarTemplatedService', 'UarNullableTypedService', 'UarSuppressedService', 'UarUnrelatedService'] as $clean) {
                self::assertStringNotContains($clean, $line);
            }
        }
    }
```

Run: `docker compose exec -T php vendor/bin/phpunit packages/nexus-psalm/tests/PluginTest.php --filter=untypedActorRef`
Expected: FAIL — `Expected 6 UntypedActorRefInjection issues` (0 found; rule doesn't exist yet).

- [ ] **Step 3: Create the issue class**

Create `packages/nexus-psalm/src/Issue/UntypedActorRefInjection.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Issue;

use Psalm\CodeLocation;
use Psalm\Issue\PluginIssue;

final class UntypedActorRefInjection extends PluginIssue
{
    public function __construct(string $subject, CodeLocation $codeLocation)
    {
        parent::__construct(
            $subject . ' must declare a concrete message type, e.g. ActorRef<MyCommand>.'
            . ' Bare ActorRef and ActorRef<object> defeat typed messaging at the injection boundary.',
            $codeLocation,
        );
    }
}
```

- [ ] **Step 4: Create the rule hook**

Create `packages/nexus-psalm/src/Hook/UntypedActorRefInjectionRule.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Hook;

use Monadial\Nexus\Psalm\Issue\UntypedActorRefInjection;
use Override;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterClassLikeAnalysisInterface;
use Psalm\Plugin\EventHandler\AfterFunctionLikeAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\AfterFunctionLikeAnalysisEvent;
use Psalm\Storage\FunctionLikeStorage;
use Psalm\Storage\MethodStorage;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TIterable;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TObject;
use Psalm\Type\Union;

use function array_merge;
use function strtolower;

final class UntypedActorRefInjectionRule implements AfterClassLikeAnalysisInterface, AfterFunctionLikeAnalysisInterface
{
    private const string ACTOR_REF = 'Monadial\Nexus\Core\Actor\ActorRef';
    private const string DEAD_LETTER_REF = 'Monadial\Nexus\Core\Actor\DeadLetterRef';

    /** @var list<string> */
    private static array $excludedRefs = [self::DEAD_LETTER_REF];

    /** @param list<string> $additionalExcludedRefs */
    public static function configure(array $additionalExcludedRefs): void
    {
        self::$excludedRefs = [self::DEAD_LETTER_REF, ...$additionalExcludedRefs];
    }

    /** @return list<string> */
    public static function excludedRefs(): array
    {
        return self::$excludedRefs;
    }

    /** @phpcsSuppress SlevomatCodingStandard.TypeHints.ReturnTypeHint */
    #[Override]
    public static function afterStatementAnalysis(AfterClassLikeAnalysisEvent $event)
    {
        $storage = $event->getClasslikeStorage();
        $codebase = $event->getCodebase();
        $suppressed = $event->getStatementsSource()->getSuppressedIssues();

        foreach ($storage->methods as $method) {
            self::checkParams($method, $storage->name, $codebase, $suppressed);
        }

        return null;
    }

    /** @phpcsSuppress SlevomatCodingStandard.TypeHints.ReturnTypeHint */
    #[Override]
    public static function afterFunctionLikeAnalysis(AfterFunctionLikeAnalysisEvent $event)
    {
        $storage = $event->getFunctionlikeStorage();

        // Class methods are handled by the class-like hook (covers interface
        // methods too, which never reach function-like analysis).
        if ($storage instanceof MethodStorage) {
            return null;
        }

        self::checkParams(
            $storage,
            $storage->cased_name ?? 'closure',
            $event->getCodebase(),
            $event->getStatementsSource()->getSuppressedIssues(),
        );

        return null;
    }

    /** @param list<string> $suppressed */
    private static function checkParams(
        FunctionLikeStorage $storage,
        string $ownerName,
        Codebase $codebase,
        array $suppressed,
    ): void {
        foreach ($storage->params as $param) {
            if ($param->promoted_property) {
                continue;
            }

            if ($param->type === null || !self::unionViolates($codebase, $param->type)) {
                continue;
            }

            $location = $param->type_location ?? $param->location;

            if ($location === null) {
                continue;
            }

            self::report(
                'ActorRef injection for parameter $' . $param->name . ' of ' . $ownerName,
                $location,
                array_merge($suppressed, $storage->suppressed_issues),
            );
        }
    }

    /** @param list<string> $suppressed */
    private static function report(string $subject, CodeLocation $location, array $suppressed): void
    {
        IssueBuffer::accepts(new UntypedActorRefInjection($subject, $location), $suppressed);
    }

    private static function unionViolates(Codebase $codebase, Union $type): bool
    {
        foreach ($type->getAtomicTypes() as $atomic) {
            if (self::atomicViolates($codebase, $atomic)) {
                return true;
            }
        }

        return false;
    }

    private static function atomicViolates(Codebase $codebase, Atomic $atomic): bool
    {
        if ($atomic instanceof TGenericObject) {
            if (self::isCheckedActorRef($codebase, $atomic->value)) {
                $messageType = $atomic->type_params[0];

                foreach ($messageType->getAtomicTypes() as $inner) {
                    // Exactly `object` — TObject, not a named class or template param.
                    if ($inner instanceof TObject && !$inner instanceof TNamedObject) {
                        return true;
                    }
                }

                return false;
            }

            return self::anyParamViolates($codebase, $atomic->type_params);
        }

        if ($atomic instanceof TNamedObject) {
            return self::isCheckedActorRef($codebase, $atomic->value);
        }

        if ($atomic instanceof TArray || $atomic instanceof TIterable) {
            return self::anyParamViolates($codebase, $atomic->type_params);
        }

        if ($atomic instanceof TKeyedArray) {
            foreach ($atomic->properties as $propertyType) {
                if (self::unionViolates($codebase, $propertyType)) {
                    return true;
                }
            }

            if ($atomic->fallback_params !== null) {
                return self::anyParamViolates($codebase, $atomic->fallback_params);
            }

            return false;
        }

        return false;
    }

    /** @param array<array-key, Union> $typeParams */
    private static function anyParamViolates(Codebase $codebase, array $typeParams): bool
    {
        foreach ($typeParams as $typeParam) {
            if (self::unionViolates($codebase, $typeParam)) {
                return true;
            }
        }

        return false;
    }

    private static function isCheckedActorRef(Codebase $codebase, string $fqClassName): bool
    {
        if (!self::isActorRef($codebase, $fqClassName)) {
            return false;
        }

        foreach (self::$excludedRefs as $excluded) {
            if (strtolower($fqClassName) === strtolower($excluded)) {
                return false;
            }

            if ($codebase->classExists($fqClassName) && $codebase->classExtends($fqClassName, $excluded)) {
                return false;
            }
        }

        return true;
    }

    private static function isActorRef(Codebase $codebase, string $fqClassName): bool
    {
        if (strtolower($fqClassName) === strtolower(self::ACTOR_REF)) {
            return true;
        }

        if ($codebase->classExists($fqClassName)) {
            return $codebase->classImplements($fqClassName, self::ACTOR_REF);
        }

        if ($codebase->interfaceExists($fqClassName)) {
            return $codebase->interfaceExtends($fqClassName, self::ACTOR_REF);
        }

        return false;
    }
}
```

Note: `TGenericObject extends TNamedObject`, so the `TGenericObject` branch must come first (it does). `TObject && !TNamedObject` guards against `TNamedObject` also matching — verify against the installed Psalm version: if `TObject` is not in `TNamedObject`'s hierarchy (it is not in Psalm 6), simplify to `$inner instanceof TObject`.

- [ ] **Step 5: Register the rule and add the psalm.xml exemptions (same commit — keeps the monorepo green)**

In `packages/nexus-psalm/src/Plugin.php`, add to imports and the `$hooks` array (alphabetical-ish placement with the other rules):

```php
use Monadial\Nexus\Psalm\Hook\UntypedActorRefInjectionRule;
```

```php
            MutableClosureCaptureRule::class,
            UntypedActorRefInjectionRule::class,
```

In `psalm.xml`, inside the existing `<issueHandlers>` element, add:

```xml
        <UntypedActorRefInjection>
            <errorLevel type="suppress">
                <!-- Heterogeneous children/registry maps: one map holds refs with different message types; PHP has no per-key generics -->
                <file name="packages/nexus-core/src/Actor/ActorCell.php"/>
                <file name="packages/nexus-core/src/Actor/ActorSystem.php"/>
                <file name="packages/nexus-http/src/Actor/PerRequestActorScope.php"/>
                <file name="packages/nexus-http/src/Actor/ResolvedActorTable.php"/>
                <file name="packages/nexus-messenger/src/Routing/MapMessageRouter.php"/>
                <file name="packages/nexus-messenger/src/Routing/StampMessageRouter.php"/>
                <file name="packages/nexus-worker-pool/src/WorkerNode.php"/>
                <!-- Parent refs: a child cannot know its parent's message type -->
                <file name="packages/nexus-core/src/Actor/TaskContext.php"/>
                <!-- Watch/lifecycle plumbing: refs carried by identity, type erased by design (Akka Typed: ActorRef[Nothing]) -->
                <file name="packages/nexus-core/src/Lifecycle/ChildFailed.php"/>
                <file name="packages/nexus-core/src/Lifecycle/Terminated.php"/>
                <file name="packages/nexus-core/src/Message/DeadLetter.php"/>
                <file name="packages/nexus-core/src/Message/Unwatch.php"/>
                <file name="packages/nexus-core/src/Message/Watch.php"/>
                <!-- Dead-letter sinks receive arbitrary unroutable messages by definition -->
                <file name="packages/nexus-messenger/src/Consumer/ReceiverActor.php"/>
                <file name="packages/nexus-messenger/src/MessengerBridge.php"/>
            </errorLevel>
        </UntypedActorRefInjection>
```

- [ ] **Step 6: Run the new tests GREEN**

Run: `docker compose exec -T php vendor/bin/phpunit packages/nexus-psalm/tests/PluginTest.php --filter=untypedActorRef`
Expected: PASS (6 issues, clean classes clean).

If the count is off, run the fixture through Psalm manually to see what fired:
`docker compose exec -T php vendor/bin/psalm --no-progress --no-cache --output-format=text packages/nexus-psalm/tests/Fixture/UntypedActorRefFixture.php`

- [ ] **Step 7: Run the full existing plugin test suite and monorepo Psalm**

Run: `docker compose exec -T php vendor/bin/phpunit packages/nexus-psalm/tests/`
Expected: PASS — no regressions in the other rules.

Run: `make psalm`
Expected: exit 0. If any src file outside the exemption list fires: **first try to fix it properly** (concrete generic or method template); only if it is genuinely by-design heterogeneous, add it to the psalm.xml list with a rationale comment.

- [ ] **Step 8: Commit**

```bash
make cs-fix && make phpcs
git add packages/nexus-psalm/src packages/nexus-psalm/tests psalm.xml
git commit -m "feat(psalm): UntypedActorRefInjection rule — injected ActorRef params must declare a concrete message type"
```

---

### Task 4: Property and promoted-param detection

**Files:**
- Modify: `packages/nexus-psalm/src/Hook/UntypedActorRefInjectionRule.php` (extend class-like hook)
- Modify: `packages/nexus-psalm/tests/Fixture/UntypedActorRefFixture.php` (new fixture classes)
- Modify: `packages/nexus-psalm/tests/PluginTest.php` (new tests + count bump)

**Interfaces:**
- Consumes: Task 3's `unionViolates()` / `report()` helpers; `PropertyStorage{type, is_static, location, stmt_location, type_location, suppressed_issues}`.
- Produces: promoted constructor params reported once (from property storage — the `promoted_property` skip in `checkParams` is already in place from Task 3).

- [ ] **Step 1: Add fixture classes (RED)**

Append to `UntypedActorRefFixture.php`:

```php
/** Bad: bare promoted constructor property. */
final class UarBarePromotedService
{
    public function __construct(private readonly ActorRef $orders) {}

    public function ping(): bool
    {
        return $this->orders->isAlive();
    }
}

/** Bad: property with @var ActorRef<object>. */
final class UarObjectPropertyService
{
    /** @var ActorRef<object>|null */
    private ?ActorRef $sink = null;

    public function bind(): bool
    {
        return $this->sink !== null;
    }
}

/** Good: promoted property with concrete generic. */
final class UarTypedPromotedService
{
    /** @param ActorRef<UarCommand> $orders */
    public function __construct(private readonly ActorRef $orders) {}

    public function ping(): bool
    {
        return $this->orders->isAlive();
    }
}

/** Good: property-level suppression. */
final class UarSuppressedPropertyService
{
    /**
     * @var ActorRef|null
     * @psalm-suppress UntypedActorRefInjection
     */
    private ?ActorRef $legacy = null;

    public function bound(): bool
    {
        return $this->legacy !== null;
    }
}
```

Add tests to `PluginTest.php`:

```php
    #[Test]
    public function untypedActorRefRuleFlagsPropertiesAndPromotedParams(): void
    {
        $output = $this->runPsalmOnFixture('UntypedActorRefFixture.php');
        $lines = $this->filterIssueLines($output, 'UntypedActorRefInjection');

        self::assertStringContains('UarBarePromotedService', $output);
        self::assertStringContains('UarObjectPropertyService', $output);

        foreach ($lines as $line) {
            self::assertStringNotContains('UarTypedPromotedService', $line);
            self::assertStringNotContains('UarSuppressedPropertyService', $line);
        }
    }
```

Update the count in `untypedActorRefRuleFlagsBareAndObjectParams` from 6 to 8 (promoted + property cases added; adjust the inline comment listing the sources).

Run: `docker compose exec -T php vendor/bin/phpunit packages/nexus-psalm/tests/PluginTest.php --filter=untypedActorRef`
Expected: FAIL — promoted/property cases not reported yet (count still 6).

- [ ] **Step 2: Extend the class-like hook (GREEN)**

In `UntypedActorRefInjectionRule::afterStatementAnalysis`, after the methods loop, add:

```php
        foreach ($storage->properties as $propertyName => $property) {
            if ($property->is_static) {
                continue;
            }

            if ($property->type === null || !self::unionViolates($codebase, $property->type)) {
                continue;
            }

            $location = $property->type_location ?? $property->location ?? $property->stmt_location;

            if ($location === null) {
                continue;
            }

            self::report(
                'ActorRef injection for property ' . $storage->name . '::$' . $propertyName,
                $location,
                array_merge($suppressed, $property->suppressed_issues),
            );
        }
```

Note: promoted constructor params get their generic from the constructor's `@param` docblock — Psalm propagates it to the property storage, so `UarTypedPromotedService` stays clean. If the property-suppress case still fires, promoted/property suppression lives on `$property->suppressed_issues` — confirm it is merged.

Run: `docker compose exec -T php vendor/bin/phpunit packages/nexus-psalm/tests/PluginTest.php --filter=untypedActorRef`
Expected: PASS.

- [ ] **Step 3: Full plugin suite + monorepo Psalm**

Run: `docker compose exec -T php vendor/bin/phpunit packages/nexus-psalm/tests/ && make psalm`
Expected: both pass. Property violations in exempted files (ActorCell/ActorSystem/WorkerNode/PerRequestActorScope maps) are silenced by the Task 3 issueHandlers block. Same triage rule as Task 3 for anything new: fix first, exempt only by-design.

- [ ] **Step 4: Commit**

```bash
make cs-fix && make phpcs
git add packages/nexus-psalm/src packages/nexus-psalm/tests psalm.xml
git commit -m "feat(psalm): UntypedActorRefInjection covers properties and promoted constructor params"
```

---

### Task 5: Plugin config for excluded refs

**Files:**
- Modify: `packages/nexus-psalm/src/Plugin.php` (parse config, call `configure()`)
- Create: `packages/nexus-psalm/tests/PluginConfigTest.php`
- Modify: `packages/nexus-psalm/tests/Fixture/UntypedActorRefFixture.php` + `PluginTest.php` (DeadLetterRef default-exclusion case)

**Interfaces:**
- Consumes: `UntypedActorRefInjectionRule::configure(list<string>)` / `::excludedRefs()` from Task 3; `Plugin::__invoke(RegistrationInterface, ?SimpleXMLElement)`.
- Produces: psalm.xml users can write `<untypedActorRefInjection><excludeRef class="…"/></untypedActorRefInjection>` inside the `<pluginClass>` element. Docs (Task 6) show this exact XML shape.

- [ ] **Step 1: Write the config-parsing unit test (RED)**

Create `packages/nexus-psalm/tests/PluginConfigTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Tests;

use Monadial\Nexus\Psalm\Hook\UntypedActorRefInjectionRule;
use Monadial\Nexus\Psalm\Plugin;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Plugin\RegistrationInterface;
use SimpleXMLElement;

final class PluginConfigTest extends TestCase
{
    private const string DEAD_LETTER_REF = 'Monadial\Nexus\Core\Actor\DeadLetterRef';

    protected function tearDown(): void
    {
        UntypedActorRefInjectionRule::configure([]);
    }

    #[Test]
    public function defaultExcludeListContainsDeadLetterRef(): void
    {
        (new Plugin())(self::registration(), null);

        self::assertSame([self::DEAD_LETTER_REF], UntypedActorRefInjectionRule::excludedRefs());
    }

    #[Test]
    public function excludeRefConfigExtendsDefaults(): void
    {
        $config = new SimpleXMLElement(
            '<pluginClass>'
            . '<untypedActorRefInjection>'
            . '<excludeRef class="App\Infra\AuditSinkRef"/>'
            . '<excludeRef class="\App\Infra\FanOutRef"/>'
            . '</untypedActorRefInjection>'
            . '</pluginClass>',
        );

        (new Plugin())(self::registration(), $config);

        self::assertSame(
            [self::DEAD_LETTER_REF, 'App\Infra\AuditSinkRef', 'App\Infra\FanOutRef'],
            UntypedActorRefInjectionRule::excludedRefs(),
        );
    }

    private static function registration(): RegistrationInterface
    {
        return new class implements RegistrationInterface {
            public function addStubFile(string $file_name): void
            {
            }

            public function registerHooksFromClass(string $handler): void
            {
            }
        };
    }
}
```

Note: check `RegistrationInterface`'s actual method list in `vendor/vimeo/psalm/src/Psalm/Plugin/RegistrationInterface.php` and implement all of its methods as no-ops (the two above are the known ones; add any others the interface declares).

Run: `docker compose exec -T php vendor/bin/phpunit packages/nexus-psalm/tests/PluginConfigTest.php`
Expected: FAIL — `excludeRefConfigExtendsDefaults` (Plugin ignores `$config`).

- [ ] **Step 2: Parse the config in Plugin (GREEN)**

In `packages/nexus-psalm/src/Plugin.php`, at the top of `__invoke` (before the hooks loop):

```php
        UntypedActorRefInjectionRule::configure(self::parseExcludedRefs($config));
```

And add the private helper (plus `use function ltrim;`):

```php
    /** @return list<string> */
    private static function parseExcludedRefs(?SimpleXMLElement $config): array
    {
        if ($config === null || !isset($config->untypedActorRefInjection)) {
            return [];
        }

        $excluded = [];

        foreach ($config->untypedActorRefInjection->excludeRef as $node) {
            $class = ltrim((string) $node['class'], '\\');

            if ($class !== '') {
                $excluded[] = $class;
            }
        }

        return $excluded;
    }
```

Run: `docker compose exec -T php vendor/bin/phpunit packages/nexus-psalm/tests/PluginConfigTest.php`
Expected: PASS.

- [ ] **Step 3: Fixture case for the DeadLetterRef default exclusion**

Append to `UntypedActorRefFixture.php`:

```php
use Monadial\Nexus\Core\Actor\DeadLetterRef;
```

(merge into the existing `use` block, alphabetically) and:

```php
/** Good: DeadLetterRef is excluded by default — accept-anything is its contract. */
final class UarDeadLetterSinkService
{
    public function __construct(private readonly DeadLetterRef $sink) {}

    public function swallow(): bool
    {
        return $this->sink->isAlive();
    }
}
```

Add to `PluginTest.php`:

```php
    #[Test]
    public function untypedActorRefRuleExcludesDeadLetterRefByDefault(): void
    {
        $output = $this->runPsalmOnFixture('UntypedActorRefFixture.php');
        $lines = $this->filterIssueLines($output, 'UntypedActorRefInjection');

        foreach ($lines as $line) {
            self::assertStringNotContains('UarDeadLetterSinkService', $line);
        }
    }
```

Run: `docker compose exec -T php vendor/bin/phpunit packages/nexus-psalm/tests/PluginTest.php --filter=untypedActorRef`
Expected: PASS — the issue count stays 8 (DeadLetterRef case never fires).

- [ ] **Step 4: Full suite + Psalm + commit**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-psalm/tests/
make psalm && make cs-fix && make phpcs
git add packages/nexus-psalm/src packages/nexus-psalm/tests
git commit -m "feat(psalm): configurable excludeRef list for UntypedActorRefInjection, DeadLetterRef excluded by default"
```

---

### Task 6: Rule documentation

**Files:**
- Modify: `website/docs/reference/psalm-rules.md`
- Modify: `website/docs/packages/psalm.md`
- Modify: `packages/nexus-psalm/README.md`

**Interfaces:**
- Consumes: issue type `UntypedActorRefInjection`, config XML shape from Task 5, issueHandlers pattern from Task 3.
- Produces: docs sections other pages may cross-link.

- [ ] **Step 1: Add the rule section to `website/docs/reference/psalm-rules.md`**

Insert after the `MutableClosureCaptureRule` section (before `PropsReturnTypeProvider`), following the page's established format:

````markdown
## UntypedActorRefInjectionRule

**Hook class:** `Monadial\Nexus\Psalm\Hook\UntypedActorRefInjectionRule`

**What it catches:** Injected `ActorRef` parameters and properties (constructor, promoted, method, closure) whose declared type does not name a concrete message type. Bare `ActorRef`, explicit `ActorRef<object>`, concrete subtypes (`LocalActorRef`, `MessengerActorRef`, …) without a generic, and refs hidden inside containers (`array<string, ActorRef>`) are all flagged.

**Issue type:** `UntypedActorRefInjection`

**Example error:**

```
ERROR: UntypedActorRefInjection: ActorRef injection for parameter $orders of App\OrderController::__construct
       must declare a concrete message type, e.g. ActorRef<MyCommand>.
       Bare ActorRef and ActorRef<object> defeat typed messaging at the injection boundary.
```

**Why:** `ActorRef<T>` is only type-safe when `T` is declared. A bare `ActorRef` injected into a controller or service accepts *any* object in `tell()` — the type safety the actor system is built around silently disappears at every DI boundary.

**Fix:**

:::caution Don't do this
```php title="src/Http/CreateOrderHandler.php" verify:lint-only
final class CreateOrderHandler
{
    public function __construct(
        #[FromActor('orders')] private readonly ActorRef $orders,  // Psalm will flag this
    ) {}
}
```
:::

```php title="src/Http/CreateOrderHandler.php"
final class CreateOrderHandler
{
    /** @param ActorRef<OrderCommand> $orders */
    public function __construct(
        #[FromActor('orders')] private readonly ActorRef $orders,
    ) {}
}
```

**Excluding accept-anything refs:** `DeadLetterRef` is excluded by default. Exclude your own heterogeneous ref classes via plugin config:

```xml title="psalm.xml"
<plugins>
    <pluginClass class="Monadial\Nexus\Psalm\Plugin">
        <untypedActorRefInjection>
            <excludeRef class="App\Infra\AuditSinkRef"/>
        </untypedActorRefInjection>
    </pluginClass>
</plugins>
```

**Exempting by-design heterogeneous positions:** where a *consumer* legitimately holds refs of mixed message types (registry maps, dead-letter sinks), scope the rule out per file with Psalm's standard mechanism instead of inline suppressions:

```xml title="psalm.xml"
<issueHandlers>
    <UntypedActorRefInjection>
        <errorLevel type="suppress">
            <!-- registry map: one map holds refs with different message types -->
            <file name="src/Infra/ActorRegistry.php"/>
        </errorLevel>
    </UntypedActorRefInjection>
</issueHandlers>
```

---
````

Also update the page intro: change "All seven rules run at Psalm level 1" to "All rules run at Psalm level 1" (the count is stale).

- [ ] **Step 2: Add the rule to `website/docs/packages/psalm.md`**

In the "Safety rules" bullet list, add (keeping list order/style):

```markdown
- `UntypedActorRefInjection` — injected `ActorRef` params/properties must declare a concrete message type (`ActorRef<MyCommand>`); bare `ActorRef` and `ActorRef<object>` are flagged, `DeadLetterRef` and configured `<excludeRef>` classes are exempt
```

- [ ] **Step 3: Add the rule to `packages/nexus-psalm/README.md`**

Read the README first and mirror its existing structure (rules list + any config examples). Add the same bullet as Step 2 and, if the README shows psalm.xml snippets, the `<untypedActorRefInjection><excludeRef …/>` block from Step 1.

- [ ] **Step 4: Build docs to verify markdown, then commit**

Run: `cd website && npm run build 2>&1 | tail -5` (skip if node_modules missing; a clean MDX parse is what matters).
Expected: build succeeds / no MDX errors mentioning the edited files.

```bash
git add website/docs/reference/psalm-rules.md website/docs/packages/psalm.md packages/nexus-psalm/README.md
git commit -m "docs(psalm): document UntypedActorRefInjection rule, excludeRef config, and issueHandlers pattern"
```

---

### Task 7: Typed-injection sweep — docs code examples and example apps

**Files:**
- Modify: `website/docs/http/handlers.md`, `website/docs/http/actors-in-http.md`, `website/docs/http/overview.md`, `website/docs/http/websockets.md`, `website/docs/packages/http.md`, `website/docs/guides/saga.md`, `website/docs/operations/observability.md`, `website/docs/operations/troubleshooting.md`, `website/docs/tutorials/tictactoe.md`
- Modify: `examples/nexus-wallet-app/src/**` and `examples/nexus-tictactoe/src/**` files with bare `ActorRef`

**Interfaces:**
- Consumes: the annotation pattern from Task 6's docs section.
- Produces: nothing downstream — this is the leaf sweep.

- [ ] **Step 1: Enumerate every bare-ActorRef example**

Run:

```bash
grep -rn ' ActorRef \$\|(ActorRef \$\|readonly ActorRef \$' website/docs examples --include='*.md' --include='*.mdx' --include='*.php' | grep -v 'ActorRef<'
```

This is the authoritative worklist — the page list above is the known starting point, the grep may surface more.

- [ ] **Step 2: Annotate each occurrence with the message type the example actually uses**

Transformation rule, applied per occurrence: find what the surrounding example sends through that ref (`$ref->tell(new X(...))` / `$ref->ask(...)`) and annotate with that type. Constructor/promoted params get `@param`, properties get `@var`, closure params get an inline `@param` docblock on the closure. Worked example (from `website/docs/http/handlers.md`):

Before:

```php
final class CreateOrderHandler
{
    public function __construct(
        #[FromActor('orders')] private readonly ActorRef $orders,
    ) {}
}
```

After (the handler body tells `CreateOrder`):

```php
final class CreateOrderHandler
{
    /** @param ActorRef<CreateOrder> $orders */
    public function __construct(
        #[FromActor('orders')] private readonly ActorRef $orders,
    ) {}
}
```

If an example genuinely sends multiple unrelated types through one ref, introduce the union of a sealed message interface if the example defines one (`ActorRef<OrderCommand>`); do not write `ActorRef<object>` in any example. For `examples/nexus-tictactoe` `GameEnvelope::$replyTo` and `GameActor` params, the reply type is what gets told back to `$replyTo` — read `GameActor` to find it.

- [ ] **Step 3: Verify examples still lint**

Examples are excluded from the monorepo Psalm run, so verify syntax only:

```bash
docker compose exec -T php sh -c 'find examples/nexus-wallet-app/src examples/nexus-tictactoe/src -name "*.php" -exec php -l {} \;' | grep -v 'No syntax errors' || true
```

Expected: no output (all files parse).

Re-run the Step 1 grep — expected: no remaining matches in the swept paths (`examples/nexus-packages` is untracked scratch; skip it).

- [ ] **Step 4: Docs build + commit**

Run: `cd website && npm run build 2>&1 | tail -5` (as in Task 6).

```bash
git add website/docs examples/nexus-wallet-app examples/nexus-tictactoe
git commit -m "docs: typed ActorRef<T> annotations in all injection examples"
```

---

### Task 8: CLAUDE.md update + final verification

**Files:**
- Modify: `CLAUDE.md` (Psalm Plugin section)

**Interfaces:**
- Consumes: everything prior.
- Produces: done.

- [ ] **Step 1: Update the CLAUDE.md Psalm plugin section**

In the `### Psalm Plugin (nexus-psalm)` section, bump the hook count ("7 hooks" → "8 hooks" — or the actual current phrasing) and add to the numbered list:

```markdown
8. **UntypedActorRefInjectionRule** — Injected `ActorRef` params/properties must declare a concrete message type (`ActorRef<T>`); bare `ActorRef` and `ActorRef<object>` flagged, `DeadLetterRef` + configured excludes exempt, by-design internals exempted via psalm.xml issueHandlers
```

- [ ] **Step 2: Full verification battery**

```bash
make psalm
make phpcs
make cs
docker compose exec -T php vendor/bin/phpunit packages/nexus-psalm/tests/
make test-unit
```

Expected: all exit 0. Fix anything that fails before committing (superpowers:verification-before-completion — evidence before claims).

- [ ] **Step 3: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: register UntypedActorRefInjectionRule in CLAUDE.md plugin list"
```

---

## Self-Review Notes

- **Spec coverage:** all injection points (Task 3 params incl. interface methods + closures + containers; Task 4 properties + promoted), `ActorRef<object>` strictness (Task 3), subtype coverage (Task 3 fixture `UarSubtypeBypassService`), container recursion (Task 3 walker + `UarContainerService` fixture), excludeRef config + DeadLetterRef default (Task 5), issueHandlers exemptions (Task 3), Bucket A/B generify (Tasks 1–2), docs + examples + CLAUDE.md (Tasks 6–8).
- **Type consistency:** `configure(list<string>)`/`excludedRefs(): list<string>` used identically in Tasks 3 and 5; issue type string `UntypedActorRefInjection` everywhere; fixture class names unique across the shared `Fixture` namespace (all `Uar`-prefixed); issue counts 6 → 8 → 8 across Tasks 3/4/5.
