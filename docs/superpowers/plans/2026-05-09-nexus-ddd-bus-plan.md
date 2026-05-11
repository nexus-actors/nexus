# Nexus DDD Bus Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Version:** v2 — applies expert-panel review fixes
**Date:** 2026-05-10
**Goal:** Implement the `nexus-ddd-bus` package — the central dispatch fabric for the Nexus DDD framework. P0 scope: ship `SyncCommandBus` / `SyncQueryBus` / `SyncEventBus` plus the canonical 12-stage middleware pipeline, the routing fabric (`BusRegistry` + `BusBuilder` + composite `RoutingStrategy`), the four pluggable slot interfaces (`Validator`, `AuthorizationDecider`, `IdempotencyStore`, `MetricsCollector`), all attributes adopters reach for at the application layer, the supporting exception hierarchy, the `RoutesShowCommand` service, six new Psalm rules in `nexus-psalm`, and the architectural fitness tests. Async / Actor bus implementations and the Postgres-backed idempotency store are explicitly out of scope and ship in P3+P4 follow-up packages.

**Architecture:** A single canonical pipeline lives in `Bus/Middleware/MiddlewarePipeline`; concrete bus impls (`SyncCommandBus`, `SyncQueryBus`, `SyncEventBus`) are thin wrappers that build the pipeline from a list of registered middlewares and dispatch a single message. Routing is decoupled from dispatch: a `CommandRouter` (composed of `RoutingStrategy` impls) resolves a command class to a registered bus name; `BusRegistry` answers "name → impl" and validates the registered set against the active `Profile` at boot. The four slots — validation, authorization, idempotency, metrics — are interfaces only in this package; concrete adapters live in dedicated packages so this package never imports Symfony / Doctrine / Redis. The `nexus-ddd-aggregate` package contributes its own `OneAggregatePerCommandMiddleware` to the pipeline at runtime; this package ships only the `Middleware` interface and the canonical pipeline composition. The package depends on `nexus-ddd-messaging` (for the `CommandBus` / `QueryBus` / `EventBus` interfaces with `tryDispatch` / `tryAsk` / `tryPublish` already on canonical, `Headers` value object, `MessageMetadata` (with `headers: Headers`), `MessageContext`, `MessageContextStack`, `Outbox`, `MessageInbox` (with `markCompleted`), `BackoffStrategy`, retry policies, and the `Accepted` marker), `nexus-ddd-core` (exceptions, `Identifier`, `DomainEvent`), `psr/log`, `psr/event-dispatcher`. It explicitly does NOT depend on `nexus-ddd-aggregate`.

**Tech Stack:** PHP 8.5+, Psalm strict (level 1), PER-CS2.0 + Slevomat, fp4php/functional (`Option<T>`, `Either<L,R>`), psr/log (PSR-3), psr/event-dispatcher (PSR-14), psr/clock (PSR-20), psr/container (PSR-11). PHPUnit 13. All commands run via Docker (`docker compose exec -T php …`). No host PHP/composer/vendor invocations. GrumPHP pre-commit hooks run in Docker.

## v1 → v2 Changelog (panel revisions applied)

This revision consumes the parallel `nexus-ddd-messaging` upstream work that lands `Headers`, `Accepted`, and rich `tryDispatch` / `tryAsk` / `tryPublish` on the canonical bus interfaces. As a result, the bus package SHEDS several v1 workarounds.

**Workarounds dropped (no longer needed; messaging upstream supplies the contract):**
- `BusHeaders` value object — replaced by `Monadial\Nexus\Ddd\Messaging\Header\Headers` consumed via `MessageMetadata::$headers` directly.
- `BusHeadersStamp` — no stamp needed; headers ride on metadata.
- `RichCommandBus` / `RichQueryBus` / `RichEventBus` extension interfaces — `tryDispatch` / `tryAsk` / `tryPublish` are on canonical messaging interfaces.
- `Accepted` defined in this package — re-exported from messaging.
- `InProcessSameDbMiddleware` runtime no-op — moved to a discrete `InProcessSameDbBootValidator` class invoked by `BusBuilder`. The `InProcessConnectionMismatchException` class still ships.
- `Phase 14` (standalone IdempotencyKeyResolver integration phase) — folded into Phase 10c.

**BLOCKING fixes (B1–B3):**
- B1 — Envelope API: middleware reads `$envelope->metadata->headers->get(...)` and writes via `$envelope->with(...)` against the shipped `with(Stamp)` and `get(class-string<Stamp>): Option<S>` API (NOT v1's invented `withStamp`/`stamp`).
- B2 — `IdempotencyMiddleware` SPLIT into `IdempotencyReserveMiddleware` (outer; before OCC retry) + `IdempotencyCommitMiddleware` (inner; INSIDE handler TX). `markCompleted` runs inside the handler's TX.
- B3 — Exception classification via `RetryableFailure` and `TerminalFailure` marker interfaces. Reserve middleware: on `RetryableFailure` → `release($token)`; on `TerminalFailure` → `markCompleted($token)`; on infrastructure → `release($token)`.

**HIGH fixes (H1–H12):**
- H1 — `InProcessSameDbMiddleware` deleted; replaced by `InProcessSameDbBootValidator` invoked from `BusBuilder`.
- H2 — `Rich*Bus` extension interfaces dropped; `SyncCommandBus implements CommandBus` directly with both `dispatchCommand` and `tryDispatch` methods.
- H3 — `BusHeaders` + `BusHeadersStamp` dropped; `HeaderKeys` constants stay in this package as `Monadial\Nexus\Ddd\Bus\Header\HeaderKeys`.
- H4 — Per-handler pipeline cache: `HandlerAttributeIndex` built at boot; `Authorize(before: 'validation')` baked into the cached pipeline.
- H5 — `BusInvariantException` marker interface; `tryDispatch` propagates boot-invariant exceptions instead of lifting to `Either::left`.
- H6 — `IdempotencyReserveMiddleware` profile-aware: self-disables under `Profile::Sync`.
- H7 — `EventDrainMiddleware` profile-aware: under `Profile::Sync` with no subscribers, no-op; under `Profile::Async`, write-then-relay; `#[InProcess]` failures rollback explicitly.
- H8 — `Composite::withStrategy(RoutingStrategy, ?class-string $before)` builder for adopter extension; `Composite::validate()` enumerates handler classes and throws `DuplicateRoutingException` when multiple strategies resolve different bus names.
- H9 — `Middleware<TIn, TOut>` templated: pipelines instantiate as `Middleware<Command, null>` for command bus, `Middleware<Query<TResult>, TResult>` for query bus.
- H10 — `BusBuilder` promoted to Phase 12a; `BusRegistry` to Phase 12b. `SyncCommandBus` constructor locked to 4 args: `(BusRegistry, HandlerAttributeIndex, MiddlewarePipeline, Profile)`.
- H11 — Each Psalm rule ships with hook-interface + AST node + Issue class + fixture pair (full shape per messaging plugin reference).
- H12 — `OccRetryMiddleware` on retry-budget exhaustion: `MetricsCollector::count('ddd.command.retry_exhausted', ...)` + PSR-3 WARN BEFORE re-throw. `IdempotencyStore::ttl(): Duration` interface contract; bus boot validates TTL ≥ max retry budget.
- H13 — `BusBuilder::withMiddleware(Middleware $m, ?PipelineStage $before = null)` for adopter / package middleware extension. Custom middleware inserts immediately before the named canonical stage; `before === null` appends after the last canonical stage. Multiple registrations targeting the same insertion point preserve registration order. `BusBuildResult::$customMiddlewares` carries the accumulated registrations so downstream pipeline assembly (Phase 13's bus constructors) can splice them into the canonical 14-stage list. Surfaced during Phase 9 design review — `PipelineStage` is intentionally a locked enum (PHP enums can't be extended from outside), so adopters need a declarative way to contribute custom middleware. `nexus-ddd-aggregate`'s `OneAggregatePerCommandMiddleware` is the canonical consumer.

**CLAUDE.md compliance (CV1–CV5):**
- CV1 — `Accepted::instance()` cached singleton DROPPED upstream; this package uses `new Accepted()` directly.
- CV2 — `Principal` interface introduced (one method: `id(): string`); `ValidationContext::$principal: Option<Principal>` and `AuthorizationContext::$principal: Option<Principal>`. The two narrow exceptions (PHP attribute defaults `Authorize::$subject: ?string`, `Authorize::$before: ?string`) documented.
- CV3 — `#[\NoDiscard]` swept across all immutable-builder + Either/Option-returning + drainer-style APIs in this package.
- CV4 — `clone($this, [...])` (PHP 8.5 clone-with) used for all VO mutators in this package.
- CV5 — Section-divider comments, restating-the-code comments, status-of-task comments, commented-out-code BANNED in code blocks. Plan retains prose docblocks only.

**MEDIUM fixes (M1–M8):**
- M1 — `IdempotencyReservation` becomes interface (`handlerClass(): string`, `idempotencyKey(): string`); each store ships its own concrete (`InMemoryReservation`).
- M2 — `BusBootException` and `BusRuntimeException` intermediate abstract classes for adopter `try/catch` ergonomics.
- M3 — `#[CommandHandler]` attribute renamed to `#[Handler]` (avoids collision with the `Monadial\Nexus\Ddd\Messaging\Handler\CommandHandler` marker interface).
- M4 — `bin/ddd` shell shim DEFERRED to `nexus-ddd-cli` (TBD); this package ships only the `Cli\Command` interface + `RoutesShowCommand` service.
- M5 — `#[Sensitive]` attribute + `LoggingMiddleware` redaction; default-deny payload-at-DEBUG.
- M6 — `MetricOutcome` enum; all metric `count` calls use it.
- M7 — Causation chain through emitted events (`EventDrainMiddleware` stamps emitted-event metadata with parent `causationId` and depth+1).
- M8 — `RetryBudget` configurable per-deployment via `NEXUS_BUS_RETRY_BUDGET_MS_SYNC` env var.

**LOW fixes (L1–L9):**
- L1 — `PipelineContext` uses `public private(set)` (PHP 8.4 asymmetric visibility).
- L2 — `RoutingResolution::$resolvedBy: class-string<RoutingStrategy>` typed; `displayName(): string` for CLI output.
- L3 — Phase 14 folded into Phase 10c (resolver too small to deserve its own phase).
- L4 — Phase 20 success criteria includes 90% method-coverage gate.
- L5 — Phase 16 ships smoke perf test: 10000 dispatches in <50ms.
- L6 — Phase 16 ships HTTP-header bridge test (`IdempotencyKeyResolver` reads `nexus.idempotency-key` from `Headers`).
- L7 — Sentinel string constants for `Authorize::$subject` documented as option; `?string` retained per attribute-default rule.
- L8 — Co-Authored-By reminder in Phase 0 conventions.
- L9 — Risk Register section after file structure.

**Phase count:** v1 had 20 phases; v2 has **19 phases** (Phase 14 folded into 10c). Sub-phases 10a/10b/10c retained; new 12a (BusBuilder) and 12b (BusRegistry) sub-phases.

**Already shipped — re-used as-is, NOT redefined:**
- `Monadial\Nexus\Ddd\Messaging\Bus\CommandBus` — `dispatchCommand(Command): void` AND `tryDispatch(Command): Either<\Throwable, Accepted>` (canonical, both methods)
- `Monadial\Nexus\Ddd\Messaging\Bus\QueryBus` — `dispatchQuery(Query): mixed` AND `tryAsk(Query<TResult>): Either<\Throwable, TResult>` (canonical, both methods)
- `Monadial\Nexus\Ddd\Messaging\Bus\EventBus` — `publishEvent(DomainEvent): void` AND `tryPublish(DomainEvent): Either<\Throwable, Accepted>` (canonical, both methods)
- `Monadial\Nexus\Ddd\Messaging\Bus\EnvelopedCommandBus` / `EnvelopedQueryBus` / `EnvelopedEventBus`
- `Monadial\Nexus\Ddd\Messaging\Handler\CommandHandler` / `QueryHandler` / `EventListener` — marker interfaces
- `Monadial\Nexus\Ddd\Messaging\Resolution\CommandHandlerLocator` / `QueryHandlerLocator` / `EventListenerLocator`
- `Monadial\Nexus\Ddd\Messaging\Identity\MessageId`
- `Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata` — `final readonly` with typed fields and **`headers: Headers`** field. `withHeaders(Headers): self` builder.
- `Monadial\Nexus\Ddd\Messaging\Header\Headers` — `final readonly` (`empty()`, `of(array)`, `get(string): Option<scalar>`, `has(string): bool`, `with(string, scalar): self`, `merge(self): self`).
- `Monadial\Nexus\Ddd\Messaging\Marker\Accepted` — `final readonly` no fields, instantiated via `new Accepted()` (no singleton cache).
- `Monadial\Nexus\Ddd\Messaging\Context\MessageContext` / `MessageContextStack`
- `Monadial\Nexus\Ddd\Messaging\Envelope\Envelope` — `final readonly class<T>`. **API: `with(Stamp): self`, `get(class-string<Stamp>): Option<S>`** (NOT `withStamp`/`stamp`).
- `Monadial\Nexus\Ddd\Messaging\Envelope\Stamp` — interface
- `Monadial\Nexus\Ddd\Messaging\Outbox\Outbox`
- `Monadial\Nexus\Ddd\Messaging\Inbox\MessageInbox` — `tryReserve`, **`markCompleted`** (renamed from `markProcessed`), `release`
- `Monadial\Nexus\Ddd\Messaging\Retry\BackoffStrategy` + impls + `RetryPolicy`
- `Monadial\Nexus\Ddd\Messaging\Exception\TerminalFailure` / `TransientFailure` markers
- `Monadial\Nexus\Ddd\Messaging\Exception\HandlerNotFoundException` / `DuplicateCommandHandlerException` / `MessagingException`
- `Monadial\Nexus\Ddd\Messaging\Message\Command` / `Query`
- `Monadial\Nexus\Ddd\Core\Exception\OptimisticLockException` — extends `DomainException`
- `Monadial\Nexus\Ddd\Core\Exception\NexusDddException`, `DomainException`

---

## File Structure

```
packages/nexus-ddd-bus/
├── composer.json
├── psalm.xml
├── phpcs.xml
├── .gitignore
├── README.md                                          # phase 19
├── src/
│   ├── Bus/
│   │   ├── SyncCommandBus.php                         # implements messaging\CommandBus + EnvelopedCommandBus
│   │   ├── SyncQueryBus.php                           # implements messaging\QueryBus + EnvelopedQueryBus
│   │   └── SyncEventBus.php                           # implements messaging\EventBus + EnvelopedEventBus
│   ├── Middleware/
│   │   ├── Middleware.php                             # interface; templated <TIn, TOut>
│   │   ├── MiddlewarePipeline.php                     # composer of stages
│   │   ├── PipelineStage.php                          # enum of canonical stage names
│   │   ├── CausationPropagationMiddleware.php         # stage 1
│   │   ├── OpenTelemetrySpanMiddleware.php            # stage 2 (no-op default)
│   │   ├── LoggingStartMiddleware.php                 # stage 3
│   │   ├── MetricsStartMiddleware.php                 # stage 4
│   │   ├── ValidationMiddleware.php                   # stage 5 (consumes Validator)
│   │   ├── AuthorizationMiddleware.php                # stage 6 (consumes AuthorizationDecider)
│   │   ├── IdempotencyReserveMiddleware.php           # stage 7a (outer; before OCC retry)
│   │   ├── OccRetryMiddleware.php                     # stage 8 (host-aware)
│   │   ├── HandlerInvocationMiddleware.php            # stage 9 inner (the actual handler call)
│   │   ├── IdempotencyCommitMiddleware.php            # stage 10 (INSIDE handler TX, post-handler pre-flush)
│   │   ├── EventDrainMiddleware.php                   # stage 11 (CommandBus only — drain & write to outbox)
│   │   ├── MetricsEndMiddleware.php                   # stage 12
│   │   ├── LoggingEndMiddleware.php                   # stage 13
│   │   └── SpanCloseMiddleware.php                    # stage 14
│   ├── Routing/
│   │   ├── BusRegistry.php                            # name → bus impl
│   │   ├── BusBuilder.php                             # builder; reflection cache; profile×strategy boot validation; withMiddleware(...) splice registrations
│   │   ├── BusBuildResult.php                         # immutable carrier: HandlerAttributeIndex + handlerMap + customMiddlewares
│   │   ├── CustomMiddlewareRegistration.php           # splice record (Middleware + optional ?PipelineStage $before)
│   │   ├── HandlerAttributeIndex.php                  # cached handler-class → ResolvedPipeline
│   │   ├── ResolvedAttributesEntry.php                # per-handler resolved attribute set + flip flags
│   │   ├── InProcessSameDbBootValidator.php           # boot-time #[InProcess] conn-binding check
│   │   ├── CommandRouter.php                          # composes RoutingStrategy impls
│   │   ├── RoutingStrategy.php                        # interface
│   │   ├── ExplicitOnly.php
│   │   ├── AttributeBased.php
│   │   ├── NamespacePattern.php
│   │   ├── Composite.php                              # walks sub-strategies in order; first match wins; withStrategy(...) extension; validate() conflict detection
│   │   └── RoutingResolution.php                      # value object: which strategy resolved + bus name + displayName()
│   ├── Idempotency/
│   │   ├── IdempotencyStore.php                       # interface (two-phase reserve/commit/release; ttl())
│   │   ├── IdempotencyReservation.php                 # interface (handlerClass(), idempotencyKey())
│   │   ├── InMemoryReservation.php                    # concrete reservation for InMemoryIdempotencyStore
│   │   ├── IdempotencyKey.php                         # final readonly class wrapping a string
│   │   ├── IdempotencyKeyResolver.php                 # reads #[IdempotencyKey] attr → MessageMetadata\Headers → messageId
│   │   └── InMemoryIdempotencyStore.php               # tests-only impl
│   ├── Validation/
│   │   ├── Validator.php                              # interface
│   │   ├── ValidationContext.php                      # final readonly class (groups, principal, headers)
│   │   ├── Violations.php                             # final readonly class (list<Violation>)
│   │   └── Violation.php                              # final readonly class
│   ├── Authorization/
│   │   ├── AuthorizationDecider.php                   # interface
│   │   ├── Principal.php                              # interface (single method: id(): string)
│   │   ├── SubjectResolver.php                        # final class
│   │   └── AuthorizationContext.php                   # final readonly class (Option<Principal>)
│   ├── Metrics/
│   │   ├── MetricsCollector.php                       # interface
│   │   ├── MetricOutcome.php                          # enum
│   │   └── NoOpMetricsCollector.php                   # default impl
│   ├── Profile/
│   │   └── Profile.php                                # enum: Sync | Async | Actor
│   ├── Header/
│   │   └── HeaderKeys.php                             # final class with public const string CAUSATION_DEPTH = 'nexus.causation.depth'; etc.
│   ├── Attribute/
│   │   ├── Handler.php                                # #[Handler] (renamed from CommandHandler — avoids collision)
│   │   ├── Validate.php                               # #[Validate(groups: array)]
│   │   ├── Authorize.php                              # #[Authorize(policy: string, subject: ?string, before: ?string)]
│   │   ├── OnBus.php                                  # #[OnBus(name: string)]
│   │   ├── IdempotencyKey.php                         # #[IdempotencyKey(field: string)]
│   │   ├── InProcess.php                              # #[InProcess]
│   │   ├── Idempotent.php                             # #[Idempotent(store: ?string, off: bool = false)]
│   │   └── Sensitive.php                              # #[Sensitive] — property-level; redacts in log payloads
│   ├── Logging/
│   │   └── PayloadRedactor.php                        # reads #[Sensitive] and redacts
│   ├── Exception/
│   │   ├── BusException.php                           # abstract; extends NexusDddException
│   │   ├── BusBootException.php                       # abstract; extends BusException
│   │   ├── BusRuntimeException.php                    # abstract; extends BusException
│   │   ├── BusInvariantException.php                  # interface (marker for boot-invariant exceptions; tryDispatch propagates)
│   │   ├── RetryableFailure.php                       # interface (OCC, transient)
│   │   ├── BusNotAvailableInProfileException.php      # extends BusBootException; implements BusInvariantException
│   │   ├── BusNameNotRegisteredException.php          # extends BusBootException; implements BusInvariantException
│   │   ├── DuplicateRoutingException.php              # extends BusBootException; implements BusInvariantException
│   │   ├── CommandReturnTypeException.php             # extends BusBootException
│   │   ├── ValidationFailedException.php              # extends DomainException; implements TerminalFailure
│   │   ├── MissingValidatorException.php              # extends BusBootException; implements BusInvariantException
│   │   ├── MissingAuthorizationDeciderException.php   # extends BusBootException; implements BusInvariantException
│   │   ├── AccessDeniedException.php                  # extends DomainException; implements TerminalFailure
│   │   ├── CausationDepthExceededException.php        # extends BusRuntimeException; implements TerminalFailure
│   │   ├── InProcessConnectionMismatchException.php   # extends BusRuntimeException (still ships for adapter use)
│   │   ├── ActorWriterInvariantViolation.php          # extends BusRuntimeException; implements TerminalFailure
│   │   └── RetryBudgetExhaustedException.php          # extends BusRuntimeException; implements RetryableFailure (caller may retry at higher level)
│   ├── Cli/
│   │   ├── Command.php                                # interface (run(array $args): string)
│   │   └── RoutesShowCommand.php                      # service; not a shell shim
│   └── Internal/
│       └── Pipeline/
│           └── PipelineContext.php                    # final class — short-lived per-dispatch scratchpad
└── tests/
    ├── Unit/
    │   ├── Bus/
    │   ├── Middleware/
    │   ├── Routing/
    │   ├── Idempotency/
    │   ├── Validation/
    │   ├── Authorization/
    │   ├── Metrics/
    │   ├── Profile/
    │   ├── Header/
    │   ├── Attribute/
    │   ├── Exception/
    │   ├── Cli/
    │   ├── Smoke/                                     # full-pipeline end-to-end on SyncCommandBus
    │   ├── Performance/                               # smoke perf (10000 dispatches < 50ms)
    │   └── Fitness/
    │       ├── PackageDependencyFitnessTest.php
    │       ├── ForbiddenImportsFitnessTest.php
    │       └── AbstractClassReadonlyOrFinalFitnessTest.php
    └── Support/
        ├── RecordingMetricsCollector.php
        ├── RecordingValidator.php
        ├── RecordingAuthorizationDecider.php
        ├── RecordingMiddleware.php
        └── Fixtures/
            ├── PlaceOrder.php
            ├── PlaceOrderHandler.php
            ├── CancelOrder.php
            └── …
```

The Psalm rules ship in `packages/nexus-psalm/src/Hook/Bus/`.

---

## Risk Register

High-risk phases that warrant extra scrutiny / a senior reviewer pre-merge:

| Phase | Risk | Mitigation |
|---|---|---|
| 10a | Causation-depth header read/write through `MessageMetadata::$headers` is the first verification that the parallel messaging upstream landed cleanly. | Phase 10a Step 1 includes a smoke test that exercises the round-trip on a synthetic envelope before any middleware logic is layered on. |
| 10c | Two-phase idempotency split (Reserve outer, Commit inner) + exception classification is the most subtle correctness fence in the package. Mis-ordering causes redelivery double-execution. | Phase 10c includes 4 dedicated TDD scenarios covering each (Retryable, Terminal, infrastructure, success) classification path. The smoke phase 16 includes a redelivery-replay test. |
| 12a | `BusBuilder` reflection cache + per-handler pipeline assembly is the load-bearing coordination point. A bug here causes silent mis-dispatch. | The builder ships with property-based fitness tests asserting that for every registered handler, the cached pipeline is structurally equivalent to a freshly-built one. |
| 12b | `BusRegistry` profile×routing validation runs at boot and must be deterministic. | Boot-validation tests cover all 9 profile×bus-impl combinations. |
| 13 | `SyncCommandBus::tryDispatch` + boot-invariant propagation. Subtle: `BusInvariantException` must NOT be lifted to `Either::left`. | Test matrix asserts each `BusBootException` propagates through `tryDispatch`; non-boot exceptions ARE lifted to `Either::left`. |
| 17 | Each Psalm rule must distinguish handlers from non-handlers reliably. False positives in adopter codebases cause adoption pain. | Each rule ships a fixture pair (triggering + clean) plus an additional ambiguity case (e.g., a class that *looks* like a handler but isn't). |

---

## Phase 0 — Branch cut + conventions

Already done — branch `feat/nexus-ddd-bus` is cut from `main` HEAD.

**Conventions for this PR (referenced from CLAUDE.md):**
- Commits MUST NOT include `Co-Authored-By: Claude` per CLAUDE.md.
- All commands run via Docker (`docker compose exec -T php …`).
- New code MUST use `Option<T>` instead of `?T`. Documented narrow exceptions: PHP attribute defaults (`Authorize::$subject: ?string`, `Authorize::$before: ?string`) and PSR-contract bodies.
- New code MUST use `clone($this, [...])` (PHP 8.5 clone-with) for value-object mutators.
- New code MUST use `#[\NoDiscard]` on builder/factory methods that return immutable instances.
- Forbidden in code blocks: section-divider comments, restating-the-code comments, status-of-task comments, commented-out code.

- [ ] **Step 1: Verify branch state**

```bash
git rev-parse --abbrev-ref HEAD
git log --oneline -1
```

If the branch is wrong or the working tree is dirty, stop and resolve before proceeding.

---

## Phase 1 — Package skeleton

**Files:**
- Create: `packages/nexus-ddd-bus/composer.json`
- Create: `packages/nexus-ddd-bus/psalm.xml`
- Create: `packages/nexus-ddd-bus/phpcs.xml`
- Create: `packages/nexus-ddd-bus/.gitignore`
- Modify: root `composer.json` (path repository entry; autoload)
- Modify: root `phpunit.xml` — add `packages/nexus-ddd-bus/tests/Unit` to the `unit` testsuite + `packages/nexus-ddd-bus/src` (and `tests/Support`) to the `<source>` whitelist
- Modify: root `deptrac.yaml` — add a `DddBus` layer for `packages/nexus-ddd-bus/src/`; allowed dependencies: `DddCore`, `DddMessaging`. Forbidden: `DddAggregate`, `Persistence*`, `Symfony*`, `Doctrine*`.

- [ ] **Step 1: Write `packages/nexus-ddd-bus/composer.json`**

```json
{
  "name": "nexus-actors/ddd-bus",
  "description": "Nexus DDD Framework — bus dispatch fabric (sync command/query/event buses, canonical 12-stage middleware pipeline, composite routing, pluggable validation/authorization/idempotency/metrics slots).",
  "type": "library",
  "license": "MIT",
  "require": {
    "php": ">=8.5",
    "fp4php/functional": "^6.0",
    "nexus-actors/ddd-core": "dev-main",
    "nexus-actors/ddd-messaging": "dev-main",
    "psr/clock": "^1.0",
    "psr/event-dispatcher": "^1.0",
    "psr/log": "^3.0"
  },
  "require-dev": {
    "phpunit/phpunit": "^13.0"
  },
  "autoload": {
    "psr-4": { "Monadial\\Nexus\\Ddd\\Bus\\": "src/" }
  },
  "autoload-dev": {
    "psr-4": { "Monadial\\Nexus\\Ddd\\Bus\\Tests\\": "tests/" }
  }
}
```

Note: no `bin` entry. The CLI shim is deferred (see Phase 15 / M4).

**Forbidden adds:** `nexus-actors/ddd-aggregate`, any `symfony/*`, any `doctrine/*`, `nexus-persistence*`. Phase 18 fitness tests enforce.

- [ ] **Step 2: Write `psalm.xml` and `phpcs.xml`** — mirror the conventions of `packages/nexus-ddd-messaging/psalm.xml`. Strict mode, level 1, no baseline.

- [ ] **Step 3: Update root `composer.json`** — autoload wildcard.

- [ ] **Step 4: Update root `phpunit.xml`** — testsuite + source whitelist.

- [ ] **Step 5: Update root `deptrac.yaml`** — `DddBus` layer with allowed deps `DddCore`, `DddMessaging`.

- [ ] **Step 6: Verify pipeline**

```bash
docker compose exec -T php composer dump-autoload --quiet
docker compose exec -T php vendor/bin/phpunit --testsuite=unit
docker compose exec -T php vendor/bin/psalm --no-cache
docker compose exec -T php vendor/bin/deptrac
docker compose exec -T php vendor/bin/phpcs packages/nexus-ddd-bus/
```

All clean.

- [ ] **Step 7: Commit**

```bash
git commit -m "feat(ddd-bus): package skeleton + composer/phpunit/deptrac wiring"
```

---

## Phase 2 — Profile enum + base exception classes + marker interfaces

**Files:**
- Create: `packages/nexus-ddd-bus/src/Profile/Profile.php`
- Create: `packages/nexus-ddd-bus/src/Exception/BusException.php` (abstract)
- Create: `packages/nexus-ddd-bus/src/Exception/BusBootException.php` (abstract)
- Create: `packages/nexus-ddd-bus/src/Exception/BusRuntimeException.php` (abstract)
- Create: `packages/nexus-ddd-bus/src/Exception/BusInvariantException.php` (interface)
- Create: `packages/nexus-ddd-bus/src/Exception/RetryableFailure.php` (interface)
- Create: `packages/nexus-ddd-bus/src/Exception/BusNotAvailableInProfileException.php`
- Create: `packages/nexus-ddd-bus/src/Exception/BusNameNotRegisteredException.php`
- Create: `packages/nexus-ddd-bus/src/Exception/DuplicateRoutingException.php`
- Create: `packages/nexus-ddd-bus/src/Exception/CommandReturnTypeException.php`
- Create: `packages/nexus-ddd-bus/src/Exception/MissingValidatorException.php`
- Create: `packages/nexus-ddd-bus/src/Exception/MissingAuthorizationDeciderException.php`
- Create: `packages/nexus-ddd-bus/src/Exception/AccessDeniedException.php`
- Create: `packages/nexus-ddd-bus/src/Exception/CausationDepthExceededException.php`
- Create: `packages/nexus-ddd-bus/src/Exception/InProcessConnectionMismatchException.php`
- Create: `packages/nexus-ddd-bus/src/Exception/ActorWriterInvariantViolation.php`
- Create: `packages/nexus-ddd-bus/src/Exception/RetryBudgetExhaustedException.php`
- Tests for all

**Inheritance graph (locked):**
- `BusException` — abstract, extends `NexusDddException`. Root for framework-wiring faults.
- `BusBootException` — abstract, extends `BusException`. All concrete boot-time-misconfig exceptions extend this AND `implements BusInvariantException`.
- `BusRuntimeException` — abstract, extends `BusException`. All runtime contract violations.
- `BusInvariantException` — marker interface. `tryDispatch` PROPAGATES these (does NOT lift to `Either::left`); `dispatchCommand` propagates them too. Adopters catch these in composition root, not in handlers.
- `RetryableFailure` — marker interface (in this package; co-equal with messaging's `TerminalFailure`).
- `AccessDeniedException` extends `DomainException` AND implements `TerminalFailure` — authorization rejection is a domain fact AND must not retry.
- `ValidationFailedException` extends `DomainException` AND implements `TerminalFailure` (deferred to Phase 4 with `Violations`).
- `ActorWriterInvariantViolation` extends `BusRuntimeException` AND implements `TerminalFailure`.
- `CausationDepthExceededException` extends `BusRuntimeException` AND implements `TerminalFailure`.
- `RetryBudgetExhaustedException` extends `BusRuntimeException` AND implements `RetryableFailure` (the caller higher up may retry at the application level).

- [ ] **Step 1: Write the `Profile` enum test FIRST**

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Profile;

use Monadial\Nexus\Ddd\Bus\Profile\Profile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Profile::class)]
final class ProfileTest extends TestCase
{
    #[Test]
    public function casesAreSyncAsyncActor(): void
    {
        self::assertSame('sync', Profile::Sync->value);
        self::assertSame('async', Profile::Async->value);
        self::assertSame('actor', Profile::Actor->value);
    }

    #[Test]
    public function isSyncReturnsTrueForSync(): void
    {
        self::assertTrue(Profile::Sync->isSync());
        self::assertFalse(Profile::Async->isSync());
    }

    #[Test]
    public function allowsAsyncBusReturnsTrueForAsyncAndActor(): void
    {
        self::assertFalse(Profile::Sync->allowsAsyncBus());
        self::assertTrue(Profile::Async->allowsAsyncBus());
        self::assertTrue(Profile::Actor->allowsAsyncBus());
    }
}
```

- [ ] **Step 2: Write the `Profile` enum**

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Ddd\Bus\Profile;

/**
 * @psalm-api
 *
 * Deployment profile selector. Determines which bus implementations are
 * available at runtime and how the OCC retry middleware behaves.
 */
enum Profile: string
{
    case Sync = 'sync';
    case Async = 'async';
    case Actor = 'actor';

    public function isSync(): bool
    {
        return $this === self::Sync;
    }

    public function allowsAsyncBus(): bool
    {
        return $this === self::Async || $this === self::Actor;
    }

    public function allowsActorBus(): bool
    {
        return $this === self::Actor;
    }
}
```

- [ ] **Step 3: Write the `BusInvariantException` and `RetryableFailure` marker interfaces**

```php
namespace Monadial\Nexus\Ddd\Bus\Exception;

/**
 * @psalm-api
 *
 * Marker for boot-invariant exceptions. tryDispatch() PROPAGATES these
 * (does NOT lift to Either::left) — boot-time configuration errors are
 * not domain failures.
 */
interface BusInvariantException {}
```

```php
namespace Monadial\Nexus\Ddd\Bus\Exception;

/**
 * @psalm-api
 *
 * Marker for retryable failures. The IdempotencyReserveMiddleware
 * RELEASES the reservation on these (allowing future redelivery).
 * Co-equal with messaging\Exception\TerminalFailure.
 */
interface RetryableFailure {}
```

- [ ] **Step 4: Write `BusException`, `BusBootException`, `BusRuntimeException` (abstract bases)**

```php
namespace Monadial\Nexus\Ddd\Bus\Exception;

use Monadial\Nexus\Ddd\Core\Exception\NexusDddException;

abstract class BusException extends NexusDddException {}

abstract class BusBootException extends BusException implements BusInvariantException {}

abstract class BusRuntimeException extends BusException {}
```

- [ ] **Step 5: Write the concrete exception classes**

The 10 concrete exceptions (`ValidationFailedException` deferred to Phase 4). Each ships with `static for(...)`-style named constructors. Slevomat alphabetical-by-key applies to associative arrays in their bodies.

`AccessDeniedException`:

```php
namespace Monadial\Nexus\Ddd\Bus\Exception;

use Monadial\Nexus\Ddd\Bus\Authorization\Principal;
use Monadial\Nexus\Ddd\Core\Exception\DomainException;
use Monadial\Nexus\Ddd\Messaging\Exception\TerminalFailure;

final class AccessDeniedException extends DomainException implements TerminalFailure
{
    /** @param string|object $subject */
    public static function for(string $policy, mixed $subject, ?Principal $principal = null): self
    {
        $subjectStr = is_scalar($subject)
            ? (string) $subject
            : get_debug_type($subject);
        $principalStr = $principal !== null
            ? sprintf(' (principal=%s)', $principal->id())
            : '';

        return new self(sprintf(
            'Access denied: principal cannot perform `%s` on `%s`%s.',
            $policy,
            $subjectStr,
            $principalStr,
        ));
    }
}
```

(`?Principal` here is permitted — `AccessDeniedException::for` accepts both call sites that have a principal and those that don't yet. Keeps the messaging-layer simpler. Documented exception.)

The other 9 exceptions follow analogous shapes.

- [ ] **Step 6: Run the hierarchy tests + Psalm + PHPCS clean**
- [ ] **Step 7: Commit**

```bash
git commit -m "feat(ddd-bus): Profile enum + exception hierarchy (BusException root with BusBoot/BusRuntime intermediates, BusInvariantException + RetryableFailure markers, 10 concrete classes)"
```

---
## Phase 3 — Re-export `Accepted` marker

**Files:**
- None new. The `Accepted` marker ships in messaging upstream as `Monadial\Nexus\Ddd\Messaging\Marker\Accepted` (`final readonly`, no fields, instantiated via `new Accepted()`).

This phase is a **smoke verification** — write a single test that confirms `new Accepted()` is constructible, no singleton cache, no factory. Lock the no-state shape so future evolution doesn't sneak fields in.

- [ ] **Step 1: Write the smoke test**

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Marker;

use Monadial\Nexus\Ddd\Messaging\Marker\Accepted;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AcceptedSmokeTest extends TestCase
{
    #[Test]
    public function isConstructibleWithoutFactory(): void
    {
        self::assertInstanceOf(Accepted::class, new Accepted());
    }

    #[Test]
    public function hasNoFields(): void
    {
        $reflection = new ReflectionClass(Accepted::class);
        self::assertSame([], $reflection->getProperties());
    }

    #[Test]
    public function hasNoStaticInstanceCache(): void
    {
        $reflection = new ReflectionClass(Accepted::class);
        $statics = $reflection->getStaticProperties();
        self::assertSame([], $statics);
    }
}
```

- [ ] **Step 2: Run, verify pass**
- [ ] **Step 3: Commit**

```bash
git commit -m "test(ddd-bus): smoke verification that messaging\Marker\Accepted has no factory/state cache"
```

---

## Phase 4 — Validation slot: `Validator` + `ValidationContext` + `Violations` + `Violation` + `ValidationFailedException` + `Principal`

**Files:**
- Create: `packages/nexus-ddd-bus/src/Authorization/Principal.php` (interface — needed early because both ValidationContext and AuthorizationContext depend on it)
- Create: `packages/nexus-ddd-bus/src/Validation/Validator.php`
- Create: `packages/nexus-ddd-bus/src/Validation/ValidationContext.php`
- Create: `packages/nexus-ddd-bus/src/Validation/Violations.php`
- Create: `packages/nexus-ddd-bus/src/Validation/Violation.php`
- Create: `packages/nexus-ddd-bus/src/Exception/ValidationFailedException.php` (deferred from Phase 2)
- Tests for each

**Lock:** `Validator::validate(object, ValidationContext): Violations` returns Violations as a value, never throws. The validation middleware lifts non-empty `Violations` to `ValidationFailedException`.

- [ ] **Step 1: Write `Principal` interface**

```php
namespace Monadial\Nexus\Ddd\Bus\Authorization;

/**
 * @psalm-api
 *
 * Application-supplied principal abstraction. Adopters provide a concrete
 * Principal implementation backed by their auth system (Symfony Security
 * UserInterface, JWT claims, custom).
 *
 * The framework never persists or serializes a Principal — adopters keep
 * lifecycle ownership.
 */
interface Principal
{
    public function id(): string;
}
```

- [ ] **Step 2: TDD `Violation` value object**

```php
namespace Monadial\Nexus\Ddd\Bus\Validation;

use NoDiscard;

/**
 * @psalm-immutable
 * @psalm-api
 */
final readonly class Violation
{
    public function __construct(
        public string $code,
        public string $message,
        public string $path,
    ) {}

    public function equals(self $other): bool
    {
        return $this->code === $other->code
            && $this->message === $other->message
            && $this->path === $other->path;
    }
}
```

- [ ] **Step 3: TDD `Violations` collection**

```php
namespace Monadial\Nexus\Ddd\Bus\Validation;

use NoDiscard;

/**
 * @psalm-immutable
 * @psalm-api
 */
final readonly class Violations
{
    /** @param list<Violation> $violations */
    public function __construct(public array $violations) {}

    #[NoDiscard('Violations::empty returns the empty collection — assign or use it')]
    public static function empty(): self
    {
        return new self([]);
    }

    public function isEmpty(): bool
    {
        return $this->violations === [];
    }

    /** @return list<Violation> */
    public function all(): array
    {
        return $this->violations;
    }

    public function count(): int
    {
        return count($this->violations);
    }

    #[NoDiscard('forPath returns a filtered collection — ignoring it loses the result')]
    public function forPath(string $path): self
    {
        return new self(array_values(array_filter(
            $this->violations,
            static fn(Violation $v): bool => $v->path === $path,
        )));
    }

    #[NoDiscard('merge returns a new collection — the originals are unchanged')]
    public function merge(self $other): self
    {
        return new self([...$this->violations, ...$other->violations]);
    }
}
```

- [ ] **Step 4: TDD `ValidationContext` value object**

```php
namespace Monadial\Nexus\Ddd\Bus\Validation;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Bus\Authorization\Principal;
use Monadial\Nexus\Ddd\Messaging\Header\Headers;
use NoDiscard;

/**
 * @psalm-immutable
 * @psalm-api
 *
 * Runtime context passed to Validator implementations. The principal is
 * Option<Principal> per the no-null rule; adopters supply a concrete
 * Principal at HTTP/CLI boundaries.
 */
final readonly class ValidationContext
{
    /**
     * @param list<string> $groups
     * @param Option<Principal> $principal
     */
    public function __construct(
        public array $groups,
        public Option $principal,
        public Headers $headers,
    ) {}

    #[NoDiscard('ValidationContext::default returns the empty context — assign or use it')]
    public static function default(): self
    {
        return new self([], Option::none(), Headers::empty());
    }

    #[NoDiscard('withGroups returns a new context — the original is unchanged')]
    public function withGroups(array $groups): self
    {
        return clone($this, ['groups' => $groups]);
    }

    #[NoDiscard('withPrincipal returns a new context — the original is unchanged')]
    public function withPrincipal(Principal $principal): self
    {
        return clone($this, ['principal' => Option::some($principal)]);
    }

    #[NoDiscard('withHeaders returns a new context — the original is unchanged')]
    public function withHeaders(Headers $headers): self
    {
        return clone($this, ['headers' => $headers]);
    }
}
```

- [ ] **Step 5: TDD `Validator` interface**

```php
namespace Monadial\Nexus\Ddd\Bus\Validation;

/**
 * @psalm-api
 *
 * Project-supplied validator. Returns Violations as a value — never throws.
 * The bus's ValidationMiddleware lifts non-empty Violations to
 * ValidationFailedException.
 */
interface Validator
{
    public function validate(object $message, ValidationContext $context): Violations;
}
```

- [ ] **Step 6: Ship `ValidationFailedException` (deferred from Phase 2)**

```php
namespace Monadial\Nexus\Ddd\Bus\Exception;

use Monadial\Nexus\Ddd\Bus\Validation\Violations;
use Monadial\Nexus\Ddd\Core\Exception\DomainException;
use Monadial\Nexus\Ddd\Messaging\Exception\TerminalFailure;

final class ValidationFailedException extends DomainException implements TerminalFailure
{
    private function __construct(
        string $message,
        private readonly Violations $violations,
    ) {
        parent::__construct($message);
    }

    public static function with(Violations $violations): self
    {
        return new self(
            sprintf('Validation failed with %d violation(s).', $violations->count()),
            $violations,
        );
    }

    public function violations(): Violations
    {
        return $this->violations;
    }
}
```

- [ ] **Step 7: Run tests + Psalm + PHPCS clean**
- [ ] **Step 8: Commit**

```bash
git commit -m "feat(ddd-bus): validation slot — Validator interface + Violations + Violation + ValidationContext (Option<Principal>) + ValidationFailedException (TerminalFailure) + Principal interface"
```

---

## Phase 5 — Authorization slot: `AuthorizationDecider` + `AuthorizationContext` + `SubjectResolver`

**Files:**
- Create: `packages/nexus-ddd-bus/src/Authorization/AuthorizationDecider.php`
- Create: `packages/nexus-ddd-bus/src/Authorization/AuthorizationContext.php`
- Create: `packages/nexus-ddd-bus/src/Authorization/SubjectResolver.php`
- Tests for each

- [ ] **Step 1: TDD `AuthorizationContext` value object**

```php
namespace Monadial\Nexus\Ddd\Bus\Authorization;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Header\Headers;
use NoDiscard;

/**
 * @psalm-immutable
 * @psalm-api
 */
final readonly class AuthorizationContext
{
    /**
     * @param Option<Principal> $principal
     * @param Envelope<object> $envelope
     */
    public function __construct(
        public Option $principal,
        public Headers $headers,
        public Envelope $envelope,
    ) {}

    #[NoDiscard('withPrincipal returns a new context — the original is unchanged')]
    public function withPrincipal(Principal $principal): self
    {
        return clone($this, ['principal' => Option::some($principal)]);
    }
}
```

- [ ] **Step 2: TDD `AuthorizationDecider` interface**

```php
namespace Monadial\Nexus\Ddd\Bus\Authorization;

use Monadial\Nexus\Ddd\Bus\Exception\AccessDeniedException;

/**
 * @psalm-api
 *
 * Project-supplied authorization decider. Throws AccessDeniedException on
 * denial. The bus middleware converts the throw to Either::left for
 * tryDispatch() callers (since AccessDeniedException implements
 * TerminalFailure but NOT BusInvariantException — domain failure, not
 * boot misconfiguration).
 */
interface AuthorizationDecider
{
    /**
     * @throws AccessDeniedException
     */
    public function decide(string $policy, mixed $subject, AuthorizationContext $context): void;
}
```

- [ ] **Step 3: TDD `SubjectResolver`**

```php
namespace Monadial\Nexus\Ddd\Bus\Authorization;

use Closure;
use Monadial\Nexus\Ddd\Messaging\Context\MessageContext;
use ReflectionObject;

/**
 * @psalm-api
 *
 * Resolves the runtime subject from #[Authorize(subject:)]. The string
 * form names a property on the message class; the callable form references
 * a public static method `'Class::method'` that receives ($message,
 * MessageContext) and returns the subject.
 */
final class SubjectResolver
{
    public function resolve(object $message, string $subjectSpec, MessageContext $ctx): mixed
    {
        if (str_contains($subjectSpec, '::')) {
            $callable = Closure::fromCallable($subjectSpec);

            return $callable($message, $ctx);
        }

        $reflection = new ReflectionObject($message);

        if (!$reflection->hasProperty($subjectSpec)) {
            throw new \LogicException(sprintf(
                'Property `%s` does not exist on `%s`. The #[Authorize(subject:)] string form names a property on the message class. Use the `Class::method` form for callable subjects.',
                $subjectSpec,
                $message::class,
            ));
        }

        return $reflection->getProperty($subjectSpec)->getValue($message);
    }
}
```

- [ ] **Step 4: Run tests + Psalm + PHPCS clean**
- [ ] **Step 5: Commit**

```bash
git commit -m "feat(ddd-bus): authorization slot — AuthorizationDecider + AuthorizationContext (Option<Principal>) + SubjectResolver (string-property + Class::method-callable forms)"
```

---

## Phase 6 — Metrics slot: `MetricsCollector` + `NoOpMetricsCollector` + `MetricOutcome`

**Files:**
- Create: `packages/nexus-ddd-bus/src/Metrics/MetricsCollector.php`
- Create: `packages/nexus-ddd-bus/src/Metrics/MetricOutcome.php`
- Create: `packages/nexus-ddd-bus/src/Metrics/NoOpMetricsCollector.php`
- Tests for each

- [ ] **Step 1: TDD `MetricOutcome` enum**

```php
namespace Monadial\Nexus\Ddd\Bus\Metrics;

/**
 * @psalm-api
 *
 * Lock outcomes here so the count tags shape is deterministic. Adapter
 * packages depend on these names verbatim (per umbrella spec §23.3).
 */
enum MetricOutcome: string
{
    case Started = 'started';
    case Succeeded = 'succeeded';
    case ValidationFailed = 'validation_failed';
    case AccessDenied = 'access_denied';
    case IdempotentShortCircuit = 'idempotent_short_circuit';
    case OccRetryExhausted = 'occ_retry_exhausted';
    case TerminalFailure = 'terminal_failure';
}
```

- [ ] **Step 2: TDD `MetricsCollector` interface**

```php
namespace Monadial\Nexus\Ddd\Bus\Metrics;

/**
 * @psalm-api
 */
interface MetricsCollector
{
    /** @param array<string, scalar> $tags */
    public function count(string $name, int $delta, array $tags): void;

    /** @param array<string, scalar> $tags */
    public function histogram(string $name, float $value, array $tags): void;

    /** @param array<string, scalar> $tags */
    public function gauge(string $name, float $value, array $tags): void;
}
```

- [ ] **Step 3: TDD `NoOpMetricsCollector`** — every method is a no-op.

```php
namespace Monadial\Nexus\Ddd\Bus\Metrics;

use Override;

final class NoOpMetricsCollector implements MetricsCollector
{
    #[Override]
    public function count(string $name, int $delta, array $tags): void {}

    #[Override]
    public function histogram(string $name, float $value, array $tags): void {}

    #[Override]
    public function gauge(string $name, float $value, array $tags): void {}
}
```

- [ ] **Step 4: Run tests + Psalm + PHPCS clean**
- [ ] **Step 5: Commit**

```bash
git commit -m "feat(ddd-bus): metrics slot — MetricsCollector interface + MetricOutcome enum + NoOpMetricsCollector"
```

---

## Phase 7 — `IdempotencyStore` two-phase contract + `IdempotencyReservation` interface + `IdempotencyKey` + `InMemoryIdempotencyStore` + `InMemoryReservation`

**Files:**
- Create: `packages/nexus-ddd-bus/src/Idempotency/IdempotencyKey.php`
- Create: `packages/nexus-ddd-bus/src/Idempotency/IdempotencyReservation.php` (interface — per M1)
- Create: `packages/nexus-ddd-bus/src/Idempotency/InMemoryReservation.php` (concrete for InMemory store)
- Create: `packages/nexus-ddd-bus/src/Idempotency/IdempotencyStore.php` (interface; includes `ttl(): Duration` per H12)
- Create: `packages/nexus-ddd-bus/src/Idempotency/InMemoryIdempotencyStore.php`
- Tests for each

**Locks:**
- `IdempotencyStore::tryReserve(class-string, IdempotencyKey): Option<IdempotencyReservation>` — `Option::some(token)` on first; `Option::none()` if already committed.
- `IdempotencyStore::markCompleted(IdempotencyReservation): void` — INSIDE handler TX.
- `IdempotencyStore::release(IdempotencyReservation): void` — terminal + retryable failure paths.
- `IdempotencyStore::ttl(): Duration` — minimum retention (used by bus boot to verify TTL ≥ max retry budget).
- `IdempotencyReservation` is now an INTERFACE (`handlerClass(): string`, `idempotencyKey(): IdempotencyKey`); each store ships its own concrete (e.g., `InMemoryReservation`).

- [ ] **Step 1: TDD `IdempotencyKey`**

```php
namespace Monadial\Nexus\Ddd\Bus\Idempotency;

/**
 * @psalm-immutable
 * @psalm-api
 */
final readonly class IdempotencyKey
{
    public function __construct(public string $value)
    {
        if ($value === '') {
            throw new \InvalidArgumentException('IdempotencyKey value cannot be empty.');
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
```

- [ ] **Step 2: TDD `IdempotencyReservation` interface**

```php
namespace Monadial\Nexus\Ddd\Bus\Idempotency;

/**
 * @psalm-api
 *
 * Two-phase reservation token. Issued by IdempotencyStore::tryReserve;
 * passed back to markCompleted (handler success) or release (failure).
 *
 * Each store ships its own concrete reservation type carrying impl-private
 * state (e.g., row id, lock token). Public observers see only the
 * (handlerClass, idempotencyKey) pair.
 */
interface IdempotencyReservation
{
    /** @return class-string */
    public function handlerClass(): string;

    public function idempotencyKey(): IdempotencyKey;
}
```

- [ ] **Step 3: TDD `InMemoryReservation`**

```php
namespace Monadial\Nexus\Ddd\Bus\Idempotency;

use Override;

/**
 * @psalm-immutable
 * @internal
 */
final readonly class InMemoryReservation implements IdempotencyReservation
{
    /** @param class-string $handlerClass */
    public function __construct(
        private string $handlerClass,
        private IdempotencyKey $idempotencyKey,
        public string $compositeKey,
    ) {}

    #[Override]
    public function handlerClass(): string
    {
        return $this->handlerClass;
    }

    #[Override]
    public function idempotencyKey(): IdempotencyKey
    {
        return $this->idempotencyKey;
    }
}
```

- [ ] **Step 4: TDD `IdempotencyStore` interface**

```php
namespace Monadial\Nexus\Ddd\Bus\Idempotency;

use Fp\Functional\Option\Option;
use Monadial\Duration\FiniteDuration;

/**
 * @psalm-api
 *
 * Two-phase idempotency contract. Per umbrella spec §13.1:
 *   tryReserve     — gates redelivery; returns Some(token) on first.
 *   markCompleted  — durable commit; runs INSIDE handler TX (per spec §13.1).
 *   release        — release on failure to allow future redelivery.
 *
 * The pipeline calls tryReserve in IdempotencyReserveMiddleware (outer;
 * before OCC retry); the OCC retry loop reuses the SAME token across
 * attempts; markCompleted runs in IdempotencyCommitMiddleware (inner;
 * post-handler, pre-flush, INSIDE the TX).
 */
interface IdempotencyStore
{
    /**
     * @param class-string $handlerClass
     * @return Option<IdempotencyReservation>  None means "already handled" — caller short-circuits.
     */
    public function tryReserve(string $handlerClass, IdempotencyKey $key): Option;

    public function markCompleted(IdempotencyReservation $token): void;

    public function release(IdempotencyReservation $token): void;

    /**
     * Minimum TTL for committed reservations. Bus boot validation requires
     * ttl() >= max retry budget across all profiles to prevent stale-eviction
     * during in-flight retry sequences.
     */
    public function ttl(): FiniteDuration;
}
```

- [ ] **Step 5: TDD `InMemoryIdempotencyStore`**

```php
namespace Monadial\Nexus\Ddd\Bus\Idempotency;

use Fp\Functional\Option\Option;
use Monadial\Duration\FiniteDuration;
use Override;

/**
 * @psalm-api
 *
 * Tests-only implementation. Production adapters live in
 * nexus-ddd-bus-idempotency-doctrine and nexus-ddd-idempotency-redis.
 */
final class InMemoryIdempotencyStore implements IdempotencyStore
{
    /** @var array<string, true> */
    private array $reserved = [];

    /** @var array<string, true> */
    private array $committed = [];

    #[Override]
    public function tryReserve(string $handlerClass, IdempotencyKey $key): Option
    {
        $compositeKey = $handlerClass . '::' . $key->value;

        if (isset($this->committed[$compositeKey]) || isset($this->reserved[$compositeKey])) {
            return Option::none();
        }

        $this->reserved[$compositeKey] = true;

        return Option::some(new InMemoryReservation($handlerClass, $key, $compositeKey));
    }

    #[Override]
    public function markCompleted(IdempotencyReservation $token): void
    {
        \assert($token instanceof InMemoryReservation);
        unset($this->reserved[$token->compositeKey]);
        $this->committed[$token->compositeKey] = true;
    }

    #[Override]
    public function release(IdempotencyReservation $token): void
    {
        \assert($token instanceof InMemoryReservation);
        unset($this->reserved[$token->compositeKey]);
    }

    #[Override]
    public function ttl(): FiniteDuration
    {
        return FiniteDuration::fromSeconds(30 * 86400);
    }
}
```

Tests cover happy path, double-reserve short-circuit, release-then-reserve, mark-completed-then-reserve, ttl returns 30d.

- [ ] **Step 6: Run tests + Psalm + PHPCS clean**
- [ ] **Step 7: Commit**

```bash
git commit -m "feat(ddd-bus): idempotency slot — IdempotencyKey + IdempotencyReservation interface + InMemoryReservation + IdempotencyStore (with ttl()) + InMemoryIdempotencyStore"
```

---

## Phase 8 — Attributes: `Validate`, `Authorize`, `OnBus`, `IdempotencyKey`, `Idempotent`, `InProcess`, `Handler`, `Sensitive`

**Files:**
- Create: `packages/nexus-ddd-bus/src/Attribute/Validate.php`
- Create: `packages/nexus-ddd-bus/src/Attribute/Authorize.php`
- Create: `packages/nexus-ddd-bus/src/Attribute/OnBus.php`
- Create: `packages/nexus-ddd-bus/src/Attribute/IdempotencyKey.php`
- Create: `packages/nexus-ddd-bus/src/Attribute/Idempotent.php`
- Create: `packages/nexus-ddd-bus/src/Attribute/InProcess.php`
- Create: `packages/nexus-ddd-bus/src/Attribute/Handler.php` (renamed from `CommandHandler` — per M3)
- Create: `packages/nexus-ddd-bus/src/Attribute/Sensitive.php` (new — per M5)
- Tests for each

**Lock M3 (attribute name collision):** the `#[Handler]` attribute lives at `Monadial\Nexus\Ddd\Bus\Attribute\Handler`. The marker interface `Monadial\Nexus\Ddd\Messaging\Handler\CommandHandler` remains canonical. Rename was necessary to avoid namespace-traverse confusion.

- [ ] **Step 1: TDD `OnBus` attribute**

```php
namespace Monadial\Nexus\Ddd\Bus\Attribute;

use Attribute;

/**
 * @psalm-api
 *
 * Routing hint per umbrella spec §8.2. Resolution order: explicit DSL →
 * #[OnBus(name:)] → namespace-pattern → default.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class OnBus
{
    public function __construct(public string $name) {}
}
```

- [ ] **Step 2: TDD `Validate` attribute**

```php
namespace Monadial\Nexus\Ddd\Bus\Attribute;

use Attribute;

/**
 * @psalm-api
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Validate
{
    /** @param list<string> $groups */
    public function __construct(public array $groups = []) {}
}
```

- [ ] **Step 3: TDD `Authorize` attribute**

```php
namespace Monadial\Nexus\Ddd\Bus\Attribute;

use Attribute;

/**
 * @psalm-api
 *
 * #[Authorize(policy: 'order.cancel', subject: 'orderId')]
 *   String form: subject names a property on the command class.
 *
 * #[Authorize(policy: 'order.cancel', subject: 'App\\Subjects\\OrderSubject::resolve')]
 *   Callable form: 'Class::method' (public static); receives ($message, MessageContext): mixed.
 *
 * The `before:` field flips pipeline ordering — set to 'validation' to
 * run Authorize before Validate. Validated by MiddlewareOrderingRule.
 *
 * The ?string types on subject and before are PHP-attribute-default
 * exceptions to the no-null rule (Option::none() is not a const expression).
 * The bus reads the attribute and immediately wraps with Option::fromNullable.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Authorize
{
    public function __construct(
        public string $policy,
        public ?string $subject = null,
        public ?string $before = null,
    ) {}
}
```

- [ ] **Step 4: TDD `IdempotencyKey` attribute**

```php
namespace Monadial\Nexus\Ddd\Bus\Attribute;

use Attribute;

/**
 * @psalm-api
 *
 *     #[IdempotencyKey(field: 'clientRequestId')]
 *     readonly class PlaceOrder { …; public string $clientRequestId; … }
 *
 * Validated by IdempotencyKeyFieldExistsRule (Phase 17).
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class IdempotencyKey
{
    public function __construct(public string $field) {}
}
```

- [ ] **Step 5: TDD `Idempotent` attribute**

```php
namespace Monadial\Nexus\Ddd\Bus\Attribute;

use Attribute;

/**
 * @psalm-api
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Idempotent
{
    public function __construct(
        public ?string $store = null,
        public bool $off = false,
    ) {}
}
```

- [ ] **Step 6: TDD `InProcess` attribute**

```php
namespace Monadial\Nexus\Ddd\Bus\Attribute;

use Attribute;

/**
 * @psalm-api
 *
 * Marks an event-handler method as in-tx. Validated at boot by
 * InProcessSameDbBootValidator (Phase 12a).
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class InProcess {}
```

- [ ] **Step 7: TDD `Handler` attribute** (renamed from CommandHandler)

```php
namespace Monadial\Nexus\Ddd\Bus\Attribute;

use Attribute;

/**
 * @psalm-api
 *
 * Secondary discoverability shortcut for multi-method services. The
 * canonical shape is implementing the
 * Monadial\Nexus\Ddd\Messaging\Handler\CommandHandler marker interface;
 * this attribute is the exception, not the rule.
 *
 *     final class OrdersService {
 *         #[Handler]
 *         public function place(PlaceOrder $cmd): void { … }
 *
 *         #[Handler]
 *         public function cancel(CancelOrder $cmd): void { … }
 *     }
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Handler {}
```

- [ ] **Step 8: TDD `Sensitive` attribute** (new — per M5)

```php
namespace Monadial\Nexus\Ddd\Bus\Attribute;

use Attribute;

/**
 * @psalm-api
 *
 * Property-level marker. The LoggingMiddleware redacts attributed
 * properties from log payloads at DEBUG.
 *
 *     readonly class PlaceOrder {
 *         public function __construct(
 *             public string $orderId,
 *             #[Sensitive] public string $cardToken,
 *         ) {}
 *     }
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Sensitive {}
```

- [ ] **Step 9: Run tests + Psalm + PHPCS clean**
- [ ] **Step 10: Commit**

```bash
git commit -m "feat(ddd-bus): eight attributes — Validate, Authorize, OnBus, IdempotencyKey, Idempotent, InProcess, Handler (renamed from CommandHandler to avoid messaging marker collision), Sensitive (payload redaction)"
```

---

## Phase 9 — `Middleware` interface (templated) + `MiddlewarePipeline` + `PipelineStage` enum + `PipelineContext`

**Files:**
- Create: `packages/nexus-ddd-bus/src/Middleware/Middleware.php` (templated)
- Create: `packages/nexus-ddd-bus/src/Middleware/MiddlewarePipeline.php`
- Create: `packages/nexus-ddd-bus/src/Middleware/PipelineStage.php`
- Create: `packages/nexus-ddd-bus/src/Internal/Pipeline/PipelineContext.php`
- Tests

**Locks:**
- `Middleware<TIn, TOut>` is a generic interface (per H9). Pipelines instantiate as `Middleware<Command, null>` for command bus, `Middleware<Query<TResult>, TResult>` for query bus.
- `PipelineStage` enum lists 14 canonical stages (was 11 in v1; the IdempotencyMiddleware split into Reserve+Commit adds 1; HandlerInvocation + EventDrain are now distinct stages 9, 11; the renumbering reflects the actual middleware-stack layout).
- `PipelineContext` is short-lived per-dispatch scratchpad with `public private(set)` asymmetric visibility (PHP 8.4) — middlewares read publicly, mutate via dedicated methods.

- [ ] **Step 1: TDD `PipelineStage` enum**

```php
namespace Monadial\Nexus\Ddd\Bus\Middleware;

/**
 * @psalm-api
 *
 * Canonical 14-stage pipeline. Lock the sequence here;
 * MiddlewareOrderingRule (Phase 17) validates that adopter-supplied
 * `before:` / `after:` arguments name an existing case.
 *
 * v2 split: stages 7a (IdempotencyReserve, outer; before OCC retry) and
 * 10 (IdempotencyCommit, inner; INSIDE handler TX, post-handler pre-flush).
 * Reserve runs OUTSIDE the OCC retry loop so retries reuse the same token;
 * Commit runs INSIDE the TX so it lands or rolls back atomically with the
 * handler's writes.
 */
enum PipelineStage: string
{
    case Causation = 'causation';
    case OtelSpan = 'otel-span';
    case LoggingStart = 'logging-start';
    case MetricsStart = 'metrics-start';
    case Validation = 'validation';
    case Authorization = 'authorization';
    case IdempotencyReserve = 'idempotency-reserve';
    case OccRetry = 'occ-retry';
    case Handler = 'handler';
    case IdempotencyCommit = 'idempotency-commit';
    case EventDrain = 'event-drain';
    case MetricsEnd = 'metrics-end';
    case LoggingEnd = 'logging-end';
    case SpanClose = 'span-close';

    /** @return list<string> */
    public static function names(): array
    {
        return array_map(static fn(self $s): string => $s->value, self::cases());
    }
}
```

- [ ] **Step 2: TDD `Middleware` interface (templated)**

```php
namespace Monadial\Nexus\Ddd\Bus\Middleware;

use Closure;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;

/**
 * @psalm-api
 *
 * Onion-style middleware. Implementations may transform the envelope
 * before calling $next($envelope), short-circuit (skip the handler),
 * inspect the return value, or wrap exceptions.
 *
 * @template TIn of object
 * @template TOut
 */
interface Middleware
{
    /**
     * @param Envelope<TIn> $envelope
     * @param Closure(Envelope<TIn>): TOut $next
     * @return TOut
     */
    public function process(Envelope $envelope, Closure $next): mixed;
}
```

- [ ] **Step 3: TDD `MiddlewarePipeline`**

```php
namespace Monadial\Nexus\Ddd\Bus\Middleware;

use Closure;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;

/**
 * @psalm-api
 *
 * Composes a list of Middleware impls into a single Closure dispatching
 * an Envelope through the canonical pipeline. Built once at boot per
 * handler (cached in HandlerAttributeIndex).
 *
 * @template TIn of object
 * @template TOut
 */
final class MiddlewarePipeline
{
    /**
     * @param list<Middleware<TIn, TOut>> $middlewares  Outermost first; innermost last.
     * @param Closure(Envelope<TIn>): TOut $core
     */
    public function __construct(
        private readonly array $middlewares,
        private readonly Closure $core,
    ) {}

    /**
     * @param Envelope<TIn> $envelope
     * @return TOut
     */
    public function dispatch(Envelope $envelope): mixed
    {
        $next = $this->core;

        foreach (array_reverse($this->middlewares) as $middleware) {
            $current = $middleware;
            $previous = $next;
            $next = static fn(Envelope $env): mixed => $current->process($env, $previous);
        }

        return $next($envelope);
    }
}
```

Tests cover empty list, single middleware wraps core, multi-middleware ordering (outermost runs first), short-circuit by inner middleware skips outer-residual stages, exceptions propagate up.

- [ ] **Step 4: TDD `PipelineContext`**

```php
namespace Monadial\Nexus\Ddd\Bus\Internal\Pipeline;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Bus\Idempotency\IdempotencyReservation;

/**
 * @internal
 *
 * Per-dispatch scratchpad. Asymmetric visibility (PHP 8.4) keeps reads
 * public while writes go through dedicated methods.
 *
 * NOT a value object — short-lived, mutable. One per dispatch.
 */
final class PipelineContext
{
    /** @var Option<IdempotencyReservation> */
    public private(set) Option $idempotencyReservation;

    public private(set) int $causationDepth = 0;

    public private(set) int $retryAttempt = 0;

    public function __construct()
    {
        $this->idempotencyReservation = Option::none();
    }

    public function rememberReservation(IdempotencyReservation $reservation): void
    {
        $this->idempotencyReservation = Option::some($reservation);
    }

    public function setCausationDepth(int $depth): void
    {
        $this->causationDepth = $depth;
    }

    public function incrementRetryAttempt(): void
    {
        $this->retryAttempt++;
    }
}
```

- [ ] **Step 5: Run tests + Psalm + PHPCS clean**
- [ ] **Step 6: Commit**

```bash
git commit -m "feat(ddd-bus): templated Middleware<TIn,TOut> + MiddlewarePipeline + PipelineStage enum (14 stages with Idempotency split into Reserve+Commit) + PipelineContext (asymmetric visibility)"
```

---
## Phase 10 — Individual middleware impls

Split into 3 sub-phases.

### Phase 10a — Causation, OTel-span, logging-start

**Files:**
- Create: `packages/nexus-ddd-bus/src/Header/HeaderKeys.php`
- Create: `packages/nexus-ddd-bus/src/Middleware/CausationPropagationMiddleware.php`
- Create: `packages/nexus-ddd-bus/src/Middleware/OpenTelemetrySpanMiddleware.php`
- Create: `packages/nexus-ddd-bus/src/Logging/PayloadRedactor.php`
- Create: `packages/nexus-ddd-bus/src/Middleware/LoggingStartMiddleware.php`
- Tests for each

**Locks:**
- Causation depth cap = 32 (configurable via `CausationPropagationMiddleware` constructor). Exceeded → `CausationDepthExceededException`.
- Depth persisted on the envelope as `MessageMetadata::$headers[HeaderKeys::CAUSATION_DEPTH]`.
- `OpenTelemetrySpanMiddleware` is a no-op default; activated when `open-telemetry/sdk` is detected.
- `LoggingStartMiddleware` writes INFO log with structured fields. Payload at DEBUG only, redacted via `PayloadRedactor` (reads `#[Sensitive]`). Default-deny: payload-at-DEBUG only when explicitly enabled by constructor flag.

- [ ] **Step 1: TDD `HeaderKeys` constants class**

```php
namespace Monadial\Nexus\Ddd\Bus\Header;

/**
 * @psalm-api
 *
 * String constants for bus header names. All keys share the `nexus.`
 * prefix to namespace against application headers. Headers ride on
 * MessageMetadata::$headers (canonical Headers value object from
 * messaging upstream).
 */
final class HeaderKeys
{
    public const string CAUSATION_DEPTH = 'nexus.causation.depth';
    public const string IDEMPOTENCY_KEY = 'nexus.idempotency-key';
    public const string PRINCIPAL = 'nexus.principal';
    public const string REPLAY = 'nexus.replay';
    public const string RETRY_ATTEMPT = 'nexus.retry.attempt';
    public const string RETRY_BUDGET_REMAINING_MS = 'nexus.retry.budget_remaining_ms';
}
```

- [ ] **Step 2: TDD `CausationPropagationMiddleware`**

Reads `Headers` directly from `MessageMetadata`; writes back via `Envelope::with(metadata->withHeaders(newHeaders))`. The Envelope API uses `metadata` directly (not via stamp).

```php
namespace Monadial\Nexus\Ddd\Bus\Middleware;

use Closure;
use Monadial\Nexus\Ddd\Bus\Exception\CausationDepthExceededException;
use Monadial\Nexus\Ddd\Bus\Header\HeaderKeys;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Override;

/**
 * @template TIn of object
 * @template TOut
 * @implements Middleware<TIn, TOut>
 */
final class CausationPropagationMiddleware implements Middleware
{
    public function __construct(private readonly int $depthCap = 32) {}

    #[Override]
    public function process(Envelope $envelope, Closure $next): mixed
    {
        $headers = $envelope->metadata->headers;

        $currentDepth = $headers->get(HeaderKeys::CAUSATION_DEPTH)
            ->map(static fn(int|string|float|bool $v): int => (int) $v)
            ->getOrElse(0);

        $newDepth = $currentDepth + 1;

        if ($newDepth > $this->depthCap) {
            throw CausationDepthExceededException::at($newDepth, $this->depthCap);
        }

        $newHeaders = $headers->with(HeaderKeys::CAUSATION_DEPTH, $newDepth);
        $newMetadata = $envelope->metadata->withHeaders($newHeaders);
        $newEnvelope = new Envelope($envelope->message, $newMetadata, $envelope->stamps);

        return $next($newEnvelope);
    }
}
```

(Note: `MessageMetadata::withHeaders(Headers): self` lands in messaging upstream alongside the new `headers` field. If the parallel agent ships a different builder shape, adapt at execution time.)

- [ ] **Step 3: TDD `OpenTelemetrySpanMiddleware` (no-op default)**

```php
namespace Monadial\Nexus\Ddd\Bus\Middleware;

use Closure;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Override;

/**
 * @template TIn of object
 * @template TOut
 * @implements Middleware<TIn, TOut>
 */
final class OpenTelemetrySpanMiddleware implements Middleware
{
    #[Override]
    public function process(Envelope $envelope, Closure $next): mixed
    {
        return $next($envelope);
    }
}
```

- [ ] **Step 4: TDD `PayloadRedactor`**

```php
namespace Monadial\Nexus\Ddd\Bus\Logging;

use Monadial\Nexus\Ddd\Bus\Attribute\Sensitive;
use ReflectionObject;

/**
 * @psalm-api
 *
 * Reflects on a message and returns an array<string, mixed> with
 * #[Sensitive]-attributed properties replaced by '[REDACTED]'.
 */
final class PayloadRedactor
{
    /** @return array<string, mixed> */
    public function redact(object $message): array
    {
        $reflection = new ReflectionObject($message);
        $output = [];

        foreach ($reflection->getProperties() as $property) {
            $hasSensitive = $property->getAttributes(Sensitive::class) !== [];
            $output[$property->getName()] = $hasSensitive
                ? '[REDACTED]'
                : $property->getValue($message);
        }

        return $output;
    }
}
```

- [ ] **Step 5: TDD `LoggingStartMiddleware`**

```php
namespace Monadial\Nexus\Ddd\Bus\Middleware;

use Closure;
use Monadial\Nexus\Ddd\Bus\Logging\PayloadRedactor;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Override;
use Psr\Log\LoggerInterface;

/**
 * @template TIn of object
 * @template TOut
 * @implements Middleware<TIn, TOut>
 */
final class LoggingStartMiddleware implements Middleware
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly PayloadRedactor $redactor,
        private readonly bool $logPayloadAtDebug = false,
    ) {}

    #[Override]
    public function process(Envelope $envelope, Closure $next): mixed
    {
        $context = [
            'causationId' => $envelope->metadata->causationId
                ->map(static fn($id): string => $id->toString())->getOrElse(''),
            'correlationId' => $envelope->metadata->correlationId
                ->map(static fn($id): string => $id->toString())->getOrElse(''),
            'messageId' => $envelope->metadata->id->toString(),
            'messageType' => $envelope->message::class,
        ];

        if ($this->logPayloadAtDebug) {
            $this->logger->debug(
                'ddd.command.dispatched.payload',
                [...$context, 'payload' => $this->redactor->redact($envelope->message)],
            );
        }

        $this->logger->info('ddd.command.dispatched', $context);

        return $next($envelope);
    }
}
```

- [ ] **Step 6: Run tests + Psalm + PHPCS clean**
- [ ] **Step 7: Commit**

```bash
git commit -m "feat(ddd-bus): pipeline stages 1-3 — causation propagation (depth cap 32; reads/writes via MessageMetadata::headers), OTel span (no-op default), logging start (default-deny payload-at-DEBUG; #[Sensitive] redaction)"
```

### Phase 10b — Validation, Authorization

**Files:**
- Create: `packages/nexus-ddd-bus/src/Middleware/MetricsStartMiddleware.php`
- Create: `packages/nexus-ddd-bus/src/Middleware/ValidationMiddleware.php`
- Create: `packages/nexus-ddd-bus/src/Middleware/AuthorizationMiddleware.php`
- Tests for each

`MetricsStartMiddleware` reads `MetricsCollector` and `MetricOutcome::Started`; passes through.

`ValidationMiddleware` reads the handler's `#[Validate]` attribute (resolved via the cached `HandlerAttributeIndex` from Phase 12a). On non-empty Violations: throws `ValidationFailedException`.

`AuthorizationMiddleware` reads the handler's `#[Authorize]` attribute, resolves the subject via `SubjectResolver`, calls `AuthorizationDecider::decide`. On `AccessDeniedException`: propagates.

**Per H4:** the per-handler `#[Authorize(before: 'validation')]` flip is baked into the cached pipeline by `BusBuilder` at boot. Each handler-class has its own pre-built `MiddlewarePipeline` instance. Runtime dispatch resolves the handler's pipeline from the index — no runtime reorder logic.

- [ ] **Step 1: TDD `MetricsStartMiddleware`**

```php
namespace Monadial\Nexus\Ddd\Bus\Middleware;

use Closure;
use Monadial\Nexus\Ddd\Bus\Metrics\MetricOutcome;
use Monadial\Nexus\Ddd\Bus\Metrics\MetricsCollector;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Override;

/**
 * @template TIn of object
 * @template TOut
 * @implements Middleware<TIn, TOut>
 */
final class MetricsStartMiddleware implements Middleware
{
    public function __construct(private readonly MetricsCollector $metrics) {}

    #[Override]
    public function process(Envelope $envelope, Closure $next): mixed
    {
        $this->metrics->count('ddd.command.count', 1, [
            'outcome' => MetricOutcome::Started->value,
            'type' => $envelope->message::class,
        ]);

        return $next($envelope);
    }
}
```

- [ ] **Step 2: TDD `ValidationMiddleware`**

```php
namespace Monadial\Nexus\Ddd\Bus\Middleware;

use Closure;
use Monadial\Nexus\Ddd\Bus\Attribute\Validate;
use Monadial\Nexus\Ddd\Bus\Exception\ValidationFailedException;
use Monadial\Nexus\Ddd\Bus\Routing\HandlerAttributeIndex;
use Monadial\Nexus\Ddd\Bus\Validation\ValidationContext;
use Monadial\Nexus\Ddd\Bus\Validation\Validator;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Override;

/**
 * @template TIn of object
 * @template TOut
 * @implements Middleware<TIn, TOut>
 */
final class ValidationMiddleware implements Middleware
{
    public function __construct(
        private readonly Validator $validator,
        private readonly HandlerAttributeIndex $index,
    ) {}

    #[Override]
    public function process(Envelope $envelope, Closure $next): mixed
    {
        $entry = $this->index->lookup($envelope->message::class);

        if ($entry->isNone() || $entry->get()->attribute(Validate::class)->isNone()) {
            return $next($envelope);
        }

        $context = ValidationContext::default()
            ->withHeaders($envelope->metadata->headers);

        $violations = $this->validator->validate($envelope->message, $context);

        if (!$violations->isEmpty()) {
            throw ValidationFailedException::with($violations);
        }

        return $next($envelope);
    }
}
```

- [ ] **Step 3: TDD `AuthorizationMiddleware`**

```php
namespace Monadial\Nexus\Ddd\Bus\Middleware;

use Closure;
use Monadial\Nexus\Ddd\Bus\Attribute\Authorize;
use Monadial\Nexus\Ddd\Bus\Authorization\AuthorizationContext;
use Monadial\Nexus\Ddd\Bus\Authorization\AuthorizationDecider;
use Monadial\Nexus\Ddd\Bus\Authorization\SubjectResolver;
use Monadial\Nexus\Ddd\Bus\Routing\HandlerAttributeIndex;
use Monadial\Nexus\Ddd\Messaging\Context\MessageContextStack;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Override;
use Fp\Functional\Option\Option;

/**
 * @template TIn of object
 * @template TOut
 * @implements Middleware<TIn, TOut>
 */
final class AuthorizationMiddleware implements Middleware
{
    public function __construct(
        private readonly AuthorizationDecider $decider,
        private readonly SubjectResolver $subjectResolver,
        private readonly HandlerAttributeIndex $index,
        private readonly MessageContextStack $contextStack,
    ) {}

    #[Override]
    public function process(Envelope $envelope, Closure $next): mixed
    {
        $entry = $this->index->lookup($envelope->message::class);

        if ($entry->isNone()) {
            return $next($envelope);
        }

        $authorize = $entry->get()->attribute(Authorize::class);

        if ($authorize->isNone()) {
            return $next($envelope);
        }

        $attribute = $authorize->get();
        $subject = $attribute->subject !== null
            ? $this->subjectResolver->resolve(
                $envelope->message,
                $attribute->subject,
                $this->contextStack->current()->getOrElse(/* synthesize from envelope */),
            )
            : null;

        $authContext = new AuthorizationContext(
            Option::none(),
            $envelope->metadata->headers,
            $envelope,
        );

        $this->decider->decide($attribute->policy, $subject, $authContext);

        return $next($envelope);
    }
}
```

- [ ] **Step 4: Run tests + Psalm + PHPCS clean**
- [ ] **Step 5: Commit**

```bash
git commit -m "feat(ddd-bus): pipeline stages 4-6 — metrics start (MetricOutcome enum), validation (lifts Violations to ValidationFailedException; reads via HandlerAttributeIndex), authorization (consumes AuthorizationDecider; default Validate→Authorize, per-handler reorder via cached pipeline)"
```

### Phase 10c — IdempotencyKeyResolver + IdempotencyReserve + OccRetry + HandlerInvocation + IdempotencyCommit + EventDrain + MetricsEnd + LoggingEnd + SpanClose

**Files:**
- Create: `packages/nexus-ddd-bus/src/Idempotency/IdempotencyKeyResolver.php`
- Create: `packages/nexus-ddd-bus/src/Middleware/IdempotencyReserveMiddleware.php` (stage 7a; OUTER, before OCC retry)
- Create: `packages/nexus-ddd-bus/src/Middleware/OccRetryMiddleware.php` (stage 8)
- Create: `packages/nexus-ddd-bus/src/Middleware/HandlerInvocationMiddleware.php` (stage 9)
- Create: `packages/nexus-ddd-bus/src/Middleware/IdempotencyCommitMiddleware.php` (stage 10; INNER, INSIDE handler TX)
- Create: `packages/nexus-ddd-bus/src/Middleware/EventDrainMiddleware.php` (stage 11; CommandBus only)
- Create: `packages/nexus-ddd-bus/src/Middleware/MetricsEndMiddleware.php`
- Create: `packages/nexus-ddd-bus/src/Middleware/LoggingEndMiddleware.php`
- Create: `packages/nexus-ddd-bus/src/Middleware/SpanCloseMiddleware.php`
- Tests for each

**This phase folds in v1's Phase 14 (`IdempotencyKeyResolver` integration) — the resolver is small enough to live alongside the middleware that consumes it.**

**Locks (B2 + B3 + H6 + H7 + H12):**
- IdempotencyReserve runs OUTSIDE OCC retry; same `messageId` + same `IdempotencyKey` across retries reuses the same reservation. Self-disables under `Profile::Sync` per H6.
- IdempotencyCommit runs INSIDE handler TX, after handler success, BEFORE EventDrain flush. `markCompleted` lands or rolls back atomically.
- Reserve middleware exception classification (B3):
  - `RetryableFailure` → `release($token)` (allow future redelivery)
  - `TerminalFailure` → `markCompleted($token)` (commit negative outcome — dedup row persists)
  - Other (infrastructure): `release($token)` (let retry resolve)
- OCC retry is host-aware. Constructor takes `Profile`. Under `Profile::Sync`, retries per `BackoffStrategy`. Under `Profile::Actor`, wraps `OptimisticLockException` in `ActorWriterInvariantViolation` and propagates. On retry-budget exhaustion: `MetricsCollector::count('ddd.command.retry_exhausted', ...)` + PSR-3 WARN BEFORE re-throw.
- Sync-route retry budget defaults to 5000ms; configurable via `NEXUS_BUS_RETRY_BUDGET_MS_SYNC` env var (M8).
- EventDrain profile-aware (H7): under `Profile::Sync` with no in-process subscribers, no-op; under `Profile::Async`, write-then-relay; `#[InProcess]` failures rollback (the handler's TX boundary is the rollback unit).
- EventDrain stamps emitted-event metadata with `causationId = sourceCommand.messageId` and depth+1 (M7).

- [ ] **Step 1: TDD `IdempotencyKeyResolver`**

```php
namespace Monadial\Nexus\Ddd\Bus\Idempotency;

use Monadial\Nexus\Ddd\Bus\Attribute\IdempotencyKey as IdempotencyKeyAttribute;
use Monadial\Nexus\Ddd\Bus\Header\HeaderKeys;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use ReflectionClass;
use ReflectionObject;

/**
 * @psalm-api
 *
 * Resolution order per umbrella spec §13.2.1 + §13.4:
 *   1. #[IdempotencyKey(field:)] attribute on the message class.
 *   2. MessageMetadata::$headers[nexus.idempotency-key].
 *   3. Fall back to messageId.
 */
final class IdempotencyKeyResolver
{
    public function resolve(Envelope $envelope): IdempotencyKey
    {
        $messageClass = $envelope->message::class;
        $attrs = (new ReflectionClass($messageClass))->getAttributes(IdempotencyKeyAttribute::class);

        if ($attrs !== []) {
            $attribute = $attrs[0]->newInstance();
            $reflection = new ReflectionObject($envelope->message);
            $value = $reflection->getProperty($attribute->field)->getValue($envelope->message);

            return new IdempotencyKey((string) $value);
        }

        $headerValue = $envelope->metadata->headers->get(HeaderKeys::IDEMPOTENCY_KEY);

        if ($headerValue->isSome()) {
            return new IdempotencyKey((string) $headerValue->get());
        }

        return new IdempotencyKey($envelope->metadata->id->toString());
    }
}
```

Tests cover all three resolution paths.

- [ ] **Step 2: TDD `IdempotencyReserveMiddleware`** (B2 + B3 + H6)

```php
namespace Monadial\Nexus\Ddd\Bus\Middleware;

use Closure;
use Monadial\Nexus\Ddd\Bus\Exception\RetryableFailure;
use Monadial\Nexus\Ddd\Bus\Idempotency\IdempotencyKeyResolver;
use Monadial\Nexus\Ddd\Bus\Idempotency\IdempotencyStore;
use Monadial\Nexus\Ddd\Bus\Profile\Profile;
use Monadial\Nexus\Ddd\Bus\Routing\HandlerAttributeIndex;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Exception\TerminalFailure;
use Override;
use Throwable;

/**
 * Outer half of the two-phase idempotency split. Runs OUTSIDE the OCC
 * retry loop so retries reuse the same reservation token.
 *
 * Self-disables under Profile::Sync (per panel H6) — sync profile has no
 * redelivery surface, so reservation is a no-op cost. Under Async/Actor,
 * the reserve+commit pair gates redelivery dedup.
 *
 * Exception classification (per panel B3):
 *   - RetryableFailure (e.g., OCC, transient infra): release($token).
 *   - TerminalFailure (validation, access-denied): markCompleted($token).
 *     Negative outcome — dedup row persists so redelivery short-circuits.
 *   - Other (uncategorized infrastructure): release($token).
 *
 * @template TIn of object
 * @template TOut
 * @implements Middleware<TIn, TOut>
 */
final class IdempotencyReserveMiddleware implements Middleware
{
    public function __construct(
        private readonly IdempotencyStore $store,
        private readonly IdempotencyKeyResolver $resolver,
        private readonly HandlerAttributeIndex $index,
        private readonly Profile $profile,
    ) {}

    #[Override]
    public function process(Envelope $envelope, Closure $next): mixed
    {
        if ($this->profile === Profile::Sync) {
            return $next($envelope);
        }

        $entry = $this->index->lookup($envelope->message::class);

        if ($entry->isSome() && $entry->get()->isIdempotencyOptedOut()) {
            return $next($envelope);
        }

        $handlerClass = $entry->map(static fn($e) => $e->handlerClass())->getOrElse('unknown');
        $key = $this->resolver->resolve($envelope);
        $reservation = $this->store->tryReserve($handlerClass, $key);

        if ($reservation->isNone()) {
            return null;
        }

        $token = $reservation->get();
        $newEnvelope = $envelope->with(new ReservationStamp($token));

        try {
            return $next($newEnvelope);
        } catch (Throwable $e) {
            if ($e instanceof TerminalFailure) {
                $this->store->markCompleted($token);
            } else {
                $this->store->release($token);
            }

            throw $e;
        }
    }
}
```

(`ReservationStamp` is a small `final readonly class implements Stamp` carrying the reservation across the OCC retry boundary; `IdempotencyCommitMiddleware` reads it from the envelope.)

- [ ] **Step 3: TDD `OccRetryMiddleware`** (H12)

```php
namespace Monadial\Nexus\Ddd\Bus\Middleware;

use Closure;
use Monadial\Nexus\Ddd\Bus\Exception\ActorWriterInvariantViolation;
use Monadial\Nexus\Ddd\Bus\Exception\RetryBudgetExhaustedException;
use Monadial\Nexus\Ddd\Bus\Metrics\MetricOutcome;
use Monadial\Nexus\Ddd\Bus\Metrics\MetricsCollector;
use Monadial\Nexus\Ddd\Bus\Profile\Profile;
use Monadial\Nexus\Ddd\Core\Exception\OptimisticLockException;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Retry\BackoffStrategy;
use Override;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * @template TIn of object
 * @template TOut
 * @implements Middleware<TIn, TOut>
 */
final class OccRetryMiddleware implements Middleware
{
    public function __construct(
        private readonly Profile $profile,
        private readonly BackoffStrategy $backoff,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly MetricsCollector $metrics,
        private readonly int $defaultBudgetMs,
    ) {}

    #[Override]
    public function process(Envelope $envelope, Closure $next): mixed
    {
        if ($this->profile === Profile::Actor) {
            try {
                return $next($envelope);
            } catch (OptimisticLockException $e) {
                throw ActorWriterInvariantViolation::forActor(
                    $envelope->message::class,
                    $envelope->metadata->id->toString(),
                    $e,
                );
            }
        }

        $start = $this->clock->now();
        $attempt = 0;

        while (true) {
            try {
                return $next($envelope);
            } catch (OptimisticLockException $e) {
                $attempt++;
                $elapsedMs = ($this->clock->now()->getTimestamp() - $start->getTimestamp()) * 1000;

                if ($elapsedMs >= $this->defaultBudgetMs) {
                    $this->metrics->count('ddd.command.retry_exhausted', 1, [
                        'type' => $envelope->message::class,
                    ]);
                    $this->logger->log(LogLevel::WARNING, 'ddd.command.retry_exhausted', [
                        'attempts' => $attempt,
                        'budget_ms' => $this->defaultBudgetMs,
                        'cause' => $e->getMessage(),
                        'messageId' => $envelope->metadata->id->toString(),
                        'type' => $envelope->message::class,
                    ]);

                    throw RetryBudgetExhaustedException::after($attempt, $this->defaultBudgetMs, $e);
                }

                $delay = $this->backoff->delayFor($attempt);

                if ($delay !== null) {
                    usleep((int) ($delay->toMicroseconds()));
                }

                continue;
            }
        }
    }
}
```

Tests:
- `Profile::Sync`, succeeds first try → no retry.
- `Profile::Sync`, throws once then succeeds → one retry.
- `Profile::Sync`, throws repeatedly until budget exhausted → metrics + WARN + `RetryBudgetExhaustedException`.
- `Profile::Sync`, throws non-OCC → propagates.
- `Profile::Actor`, throws OCC → wraps as `ActorWriterInvariantViolation`.

- [ ] **Step 4: TDD `HandlerInvocationMiddleware`**

```php
namespace Monadial\Nexus\Ddd\Bus\Middleware;

use Closure;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Exception\HandlerNotFoundException;
use Monadial\Nexus\Ddd\Messaging\Resolution\CommandHandlerLocator;
use Override;

/**
 * @template TIn of object
 * @template TOut
 * @implements Middleware<TIn, TOut>
 */
final class HandlerInvocationMiddleware implements Middleware
{
    public function __construct(private readonly CommandHandlerLocator $locator) {}

    #[Override]
    public function process(Envelope $envelope, Closure $next): mixed
    {
        $info = $this->locator->locate($envelope->message::class);
        $handler = $info->getOrElseThrow(
            static fn() => HandlerNotFoundException::forCommand($envelope->message::class),
        );

        $handler($envelope->message);

        return $next($envelope);
    }
}
```

- [ ] **Step 5: TDD `IdempotencyCommitMiddleware`** (B2)

```php
namespace Monadial\Nexus\Ddd\Bus\Middleware;

use Closure;
use Monadial\Nexus\Ddd\Bus\Idempotency\IdempotencyStore;
use Monadial\Nexus\Ddd\Bus\Profile\Profile;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Override;

/**
 * Inner half of the two-phase idempotency split. Runs INSIDE the handler
 * TX, AFTER HandlerInvocation, BEFORE EventDrain flush. markCompleted()
 * lands or rolls back atomically with the handler's writes (per spec §13.1).
 *
 * Self-disables under Profile::Sync (mirrors IdempotencyReserveMiddleware
 * H6). The Reserve middleware doesn't reserve under Sync, so there's no
 * token to commit.
 *
 * @template TIn of object
 * @template TOut
 * @implements Middleware<TIn, TOut>
 */
final class IdempotencyCommitMiddleware implements Middleware
{
    public function __construct(
        private readonly IdempotencyStore $store,
        private readonly Profile $profile,
    ) {}

    #[Override]
    public function process(Envelope $envelope, Closure $next): mixed
    {
        $result = $next($envelope);

        if ($this->profile === Profile::Sync) {
            return $result;
        }

        $stamp = $envelope->get(ReservationStamp::class);

        if ($stamp->isSome()) {
            $this->store->markCompleted($stamp->get()->reservation);
        }

        return $result;
    }
}
```

- [ ] **Step 6: TDD `EventDrainMiddleware`** (H7 + M7)

```php
namespace Monadial\Nexus\Ddd\Bus\Middleware;

use Closure;
use Monadial\Nexus\Ddd\Bus\Profile\Profile;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Outbox\Outbox;
use Override;

/**
 * Drains recorded events from the active aggregate(s) into the outbox.
 *
 * Profile-aware (per panel H7):
 *   - Sync + no in-process subscribers: no-op.
 *   - Sync + in-process subscribers: drain in-tx; #[InProcess] failure
 *     causes the handler's TX boundary to roll back.
 *   - Async / Actor: write-then-relay; the relay process runs
 *     independently after commit.
 *
 * Causation chain (per panel M7): emitted events stamp metadata with
 * causationId = sourceCommand.messageId and depth+1 — handled by the
 * Outbox::flush() implementation downstream.
 *
 * @template TIn of object
 * @template TOut
 * @implements Middleware<TIn, TOut>
 */
final class EventDrainMiddleware implements Middleware
{
    public function __construct(
        private readonly Outbox $outbox,
        private readonly Profile $profile,
    ) {}

    #[Override]
    public function process(Envelope $envelope, Closure $next): mixed
    {
        $result = $next($envelope);

        $this->outbox->flush();

        return $result;
    }
}
```

(The profile branching in `Outbox::flush()` lives in messaging upstream — the outbox impl is profile-aware. From the bus side, we always call `flush()` and let the impl decide. The middleware constructor takes `Profile` for future use — adapter packages that need profile-aware behavior at the middleware layer can subclass / replace.)

- [ ] **Step 7: TDD `MetricsEndMiddleware`, `LoggingEndMiddleware`, `SpanCloseMiddleware`** — symmetric exits.

- [ ] **Step 8: Run tests + Psalm + PHPCS clean**
- [ ] **Step 9: Commit**

```bash
git commit -m "feat(ddd-bus): pipeline stages 7-14 — IdempotencyKeyResolver + IdempotencyReserveMiddleware (outer; profile-aware self-disable on Sync; Retryable→release / Terminal→markCompleted classification) + OccRetryMiddleware (host-aware: Sync retries with budget + metrics + WARN on exhaustion; Actor wraps as ActorWriterInvariantViolation) + HandlerInvocation + IdempotencyCommitMiddleware (INSIDE handler TX; profile-aware) + EventDrain (profile-aware; causation chain) + MetricsEnd + LoggingEnd + SpanClose"
```

---

## Phase 11 — `RoutingStrategy` interface + 4 impls (`ExplicitOnly`, `AttributeBased`, `NamespacePattern`, `Composite`) + `RoutingResolution`

**Files:**
- Create: `packages/nexus-ddd-bus/src/Routing/RoutingStrategy.php`
- Create: `packages/nexus-ddd-bus/src/Routing/RoutingResolution.php`
- Create: `packages/nexus-ddd-bus/src/Routing/ExplicitOnly.php`
- Create: `packages/nexus-ddd-bus/src/Routing/AttributeBased.php`
- Create: `packages/nexus-ddd-bus/src/Routing/NamespacePattern.php`
- Create: `packages/nexus-ddd-bus/src/Routing/Composite.php`
- Tests for each

**Locks (H8 + L2):**
- `Composite::withStrategy(RoutingStrategy, ?class-string<RoutingStrategy> $before = null)` builder for adopter extension. Insert at position before another strategy class.
- `Composite::validate(iterable<class-string> $handlerClasses): void` — enumerates handler classes and throws `DuplicateRoutingException` when multiple strategies resolve different bus names for the same class.
- `RoutingResolution::$resolvedBy: class-string<RoutingStrategy>` (typed); `displayName(): string` for CLI output.

- [ ] **Step 1: TDD `RoutingStrategy` interface**

```php
namespace Monadial\Nexus\Ddd\Bus\Routing;

use Fp\Functional\Option\Option;

/**
 * @psalm-api
 */
interface RoutingStrategy
{
    /**
     * @param class-string $messageClass
     * @return Option<RoutingResolution>
     */
    public function resolve(string $messageClass): Option;
}
```

- [ ] **Step 2: TDD `RoutingResolution` value object**

```php
namespace Monadial\Nexus\Ddd\Bus\Routing;

/**
 * @psalm-immutable
 * @psalm-api
 */
final readonly class RoutingResolution
{
    /** @param class-string<RoutingStrategy> $resolvedBy */
    public function __construct(
        public string $busName,
        public string $resolvedBy,
    ) {}

    public function displayName(): string
    {
        $parts = explode('\\', $this->resolvedBy);

        return end($parts);
    }
}
```

- [ ] **Step 3: TDD `ExplicitOnly`**

```php
namespace Monadial\Nexus\Ddd\Bus\Routing;

use Fp\Functional\Option\Option;
use NoDiscard;
use Override;

/**
 * @psalm-api
 *
 * Highest-priority strategy in the Composite chain.
 */
final class ExplicitOnly implements RoutingStrategy
{
    /** @var array<class-string, string> */
    private array $routes = [];

    /** @param class-string $messageClass */
    #[NoDiscard('explicit() returns this — assign or chain')]
    public function explicit(string $messageClass, string $busName): self
    {
        $this->routes[$messageClass] = $busName;

        return $this;
    }

    #[Override]
    public function resolve(string $messageClass): Option
    {
        return Option::fromNullable($this->routes[$messageClass] ?? null)
            ->map(fn(string $busName): RoutingResolution => new RoutingResolution($busName, self::class));
    }
}
```

- [ ] **Step 4: TDD `AttributeBased`**

```php
namespace Monadial\Nexus\Ddd\Bus\Routing;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Bus\Attribute\OnBus;
use Override;
use ReflectionClass;

final class AttributeBased implements RoutingStrategy
{
    #[Override]
    public function resolve(string $messageClass): Option
    {
        $attrs = (new ReflectionClass($messageClass))->getAttributes(OnBus::class);

        if ($attrs === []) {
            return Option::none();
        }

        $name = $attrs[0]->newInstance()->name;

        return Option::some(new RoutingResolution($name, self::class));
    }
}
```

- [ ] **Step 5: TDD `NamespacePattern`**

```php
namespace Monadial\Nexus\Ddd\Bus\Routing;

use Fp\Functional\Option\Option;
use NoDiscard;
use Override;

final class NamespacePattern implements RoutingStrategy
{
    /** @var list<array{busName: string, pattern: string}> */
    private array $patterns = [];

    #[NoDiscard('namespace() returns this — assign or chain')]
    public function namespace(string $pattern, string $busName): self
    {
        $this->patterns[] = ['busName' => $busName, 'pattern' => $pattern];

        return $this;
    }

    #[Override]
    public function resolve(string $messageClass): Option
    {
        foreach ($this->patterns as $entry) {
            if (fnmatch($entry['pattern'], $messageClass, FNM_NOESCAPE)) {
                return Option::some(new RoutingResolution($entry['busName'], self::class));
            }
        }

        return Option::none();
    }
}
```

- [ ] **Step 6: TDD `Composite`** (H8)

```php
namespace Monadial\Nexus\Ddd\Bus\Routing;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Bus\Exception\DuplicateRoutingException;
use NoDiscard;
use Override;

/**
 * @psalm-api
 *
 * Walks sub-strategies in registration order; first Some(...) wins. Per
 * umbrella spec §8.2, the standard order is ExplicitOnly →
 * AttributeBased → NamespacePattern → fallback to default.
 *
 * `withStrategy(...)` appends a new strategy or inserts before another by
 * class name. `validate(handlerClasses)` enumerates each class and
 * throws DuplicateRoutingException when multiple strategies resolve
 * different bus names — useful at boot time to catch misconfiguration.
 */
final class Composite implements RoutingStrategy
{
    /** @param list<RoutingStrategy> $strategies */
    public function __construct(
        private readonly array $strategies,
        private readonly string $defaultBusName,
    ) {}

    #[Override]
    public function resolve(string $messageClass): Option
    {
        foreach ($this->strategies as $strategy) {
            $resolution = $strategy->resolve($messageClass);

            if ($resolution->isSome()) {
                return $resolution;
            }
        }

        return Option::some(new RoutingResolution($this->defaultBusName, self::class));
    }

    /**
     * @param class-string<RoutingStrategy>|null $before  Insert before this strategy class; null = append.
     */
    #[NoDiscard('withStrategy returns a new Composite — the original is unchanged')]
    public function withStrategy(RoutingStrategy $strategy, ?string $before = null): self
    {
        if ($before === null) {
            return new self([...$this->strategies, $strategy], $this->defaultBusName);
        }

        $output = [];

        foreach ($this->strategies as $existing) {
            if ($existing::class === $before) {
                $output[] = $strategy;
            }

            $output[] = $existing;
        }

        return new self($output, $this->defaultBusName);
    }

    /**
     * @param iterable<class-string> $handlerClasses
     * @throws DuplicateRoutingException when two strategies resolve different busNames for the same class.
     */
    public function validate(iterable $handlerClasses): void
    {
        foreach ($handlerClasses as $handlerClass) {
            $resolutions = [];

            foreach ($this->strategies as $strategy) {
                $resolution = $strategy->resolve($handlerClass);

                if ($resolution->isSome()) {
                    $resolutions[$strategy::class] = $resolution->get()->busName;
                }
            }

            $unique = array_unique(array_values($resolutions));

            if (count($unique) > 1) {
                $sources = array_keys($resolutions);

                throw DuplicateRoutingException::between($handlerClass, $sources[0], $sources[1]);
            }
        }
    }
}
```

- [ ] **Step 7: Run tests + Psalm + PHPCS clean**
- [ ] **Step 8: Commit**

```bash
git commit -m "feat(ddd-bus): RoutingStrategy interface + 4 impls (ExplicitOnly, AttributeBased, NamespacePattern, Composite with withStrategy(before:) extension + validate() conflict detection) + RoutingResolution VO with displayName()"
```

---
## Phase 12a — `BusBuilder` + `HandlerAttributeIndex` + `InProcessSameDbBootValidator`

**Files:**
- Create: `packages/nexus-ddd-bus/src/Routing/BusBuilder.php`
- Create: `packages/nexus-ddd-bus/src/Routing/HandlerAttributeIndex.php`
- Create: `packages/nexus-ddd-bus/src/Routing/InProcessSameDbBootValidator.php`
- Tests

**Locks (H4 + H10 + H1 + H13):**
- `BusBuilder` is the boot orchestrator. Reflects all registered handler classes; builds a `HandlerAttributeIndex` (cache: `class-string<handler> → ResolvedAttributesEntry`); assembles the per-handler `MiddlewarePipeline` with the `#[Authorize(before: 'validation')]` flip baked in.
- `HandlerAttributeIndex::lookup(class-string<message>): Option<ResolvedAttributesEntry>` — middlewares consume this at runtime instead of doing reflection on every dispatch.
- `InProcessSameDbBootValidator` (replaces v1's `InProcessSameDbMiddleware` no-op) — boot-time only; checks that every `#[InProcess]`-attributed handler's bound connection matches its source aggregate's bound connection. Throws `InProcessConnectionMismatchException` if mismatch.
- `BusBuilder::withMiddleware(Middleware $m, ?PipelineStage $before = null): self` — declarative adopter / package middleware extension. Accumulates `CustomMiddlewareRegistration` records that `BusBuildResult` carries to the downstream pipeline assembler (Phase 13). `before === null` → append after `SpanClose` (last canonical stage). `before === PipelineStage::X` → insert immediately before the canonical `X` middleware. Multiple registrations targeting the same `$before` preserve registration order.

- [ ] **Step 1: TDD `HandlerAttributeIndex`**

```php
namespace Monadial\Nexus\Ddd\Bus\Routing;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Bus\Attribute\Authorize;
use Monadial\Nexus\Ddd\Bus\Attribute\Idempotent;
use Monadial\Nexus\Ddd\Bus\Attribute\Validate;

/**
 * @psalm-api
 *
 * Pre-computed cache built once at boot. Lookups are O(1) by message class.
 * Each entry carries the resolved handler class and its attribute set.
 */
final class HandlerAttributeIndex
{
    /** @var array<class-string, ResolvedAttributesEntry> */
    private readonly array $entries;

    /** @param array<class-string, ResolvedAttributesEntry> $entries */
    public function __construct(array $entries)
    {
        $this->entries = $entries;
    }

    /**
     * @param class-string $messageClass
     * @return Option<ResolvedAttributesEntry>
     */
    public function lookup(string $messageClass): Option
    {
        return Option::fromNullable($this->entries[$messageClass] ?? null);
    }

    /** @return iterable<class-string, ResolvedAttributesEntry> */
    public function all(): iterable
    {
        return $this->entries;
    }
}
```

```php
namespace Monadial\Nexus\Ddd\Bus\Routing;

use Fp\Functional\Option\Option;

/**
 * @psalm-api
 * @psalm-immutable
 */
final readonly class ResolvedAttributesEntry
{
    /**
     * @param class-string $handlerClass
     * @param array<class-string, object> $attributes
     */
    public function __construct(
        public string $handlerClass,
        public array $attributes,
        public bool $authorizeBeforeValidate,
        public bool $idempotencyOptedOut,
    ) {}

    /**
     * @template T of object
     * @param class-string<T> $attributeClass
     * @return Option<T>
     */
    public function attribute(string $attributeClass): Option
    {
        return Option::fromNullable($this->attributes[$attributeClass] ?? null);
    }

    public function handlerClass(): string
    {
        return $this->handlerClass;
    }

    public function isIdempotencyOptedOut(): bool
    {
        return $this->idempotencyOptedOut;
    }
}
```

- [ ] **Step 2: TDD `InProcessSameDbBootValidator`**

```php
namespace Monadial\Nexus\Ddd\Bus\Routing;

use Monadial\Nexus\Ddd\Bus\Attribute\InProcess;
use Monadial\Nexus\Ddd\Bus\Exception\InProcessConnectionMismatchException;
use ReflectionClass;
use ReflectionMethod;

/**
 * @psalm-api
 *
 * Boot-time validator (replaces v1's runtime no-op middleware per panel
 * H1). For every #[InProcess]-attributed handler, asserts that the
 * handler's declared connection name matches the source aggregate's
 * connection name as registered in $bindings.
 *
 * Adopters supply $bindings as a map class-string → connection-name at
 * application bootstrap. Without bindings, the validator passes (no
 * basis for comparison).
 */
final class InProcessSameDbBootValidator
{
    /**
     * @param array<class-string, string> $bindings
     */
    public function __construct(private readonly array $bindings) {}

    /**
     * @param iterable<class-string> $handlerClasses
     * @throws InProcessConnectionMismatchException
     */
    public function validate(iterable $handlerClasses): void
    {
        foreach ($handlerClasses as $handlerClass) {
            $reflection = new ReflectionClass($handlerClass);

            foreach ($reflection->getMethods() as $method) {
                if ($method->getAttributes(InProcess::class) === []) {
                    continue;
                }

                $this->checkMethodBinding($method);
            }
        }
    }

    private function checkMethodBinding(ReflectionMethod $method): void
    {
        $params = $method->getParameters();

        if ($params === []) {
            return;
        }

        $eventClass = $params[0]->getType();

        if ($eventClass === null) {
            return;
        }

        $aggregateConn = $this->bindings[$eventClass->__toString()] ?? null;
        $handlerConn = $this->bindings[$method->getDeclaringClass()->getName()] ?? null;

        if ($aggregateConn !== null && $handlerConn !== null && $aggregateConn !== $handlerConn) {
            throw InProcessConnectionMismatchException::between($aggregateConn, $handlerConn);
        }
    }
}
```

- [ ] **Step 3: TDD `BusBuilder`**

```php
namespace Monadial\Nexus\Ddd\Bus\Routing;

use Closure;
use Monadial\Nexus\Ddd\Bus\Attribute\Authorize;
use Monadial\Nexus\Ddd\Bus\Attribute\Idempotent;
use Monadial\Nexus\Ddd\Bus\Attribute\Validate;
use Monadial\Nexus\Ddd\Bus\Exception\MissingAuthorizationDeciderException;
use Monadial\Nexus\Ddd\Bus\Exception\MissingValidatorException;
use Monadial\Nexus\Ddd\Bus\Middleware\Middleware;
use Monadial\Nexus\Ddd\Bus\Middleware\PipelineStage;
use Monadial\Nexus\Ddd\Bus\Profile\Profile;
use NoDiscard;
use ReflectionClass;
use ReflectionMethod;

/**
 * @psalm-api
 *
 * Boot orchestrator. Reflects all registered handler classes once;
 * builds the HandlerAttributeIndex (cached) and the per-handler
 * MiddlewarePipeline; runs all boot validators (missing-validator,
 * missing-decider, in-process-same-db, composite-routing-conflict).
 *
 * The builder is final + non-readonly because it accumulates registrations
 * during construction. After build(), it produces an immutable BusRegistry
 * + HandlerAttributeIndex + custom-middleware splice list.
 */
final class BusBuilder
{
    /** @var array<class-string, class-string> message-class → handler-class */
    private array $handlers = [];

    /** @var array<string, string> connection bindings */
    private array $bindings = [];

    /** @var list<CustomMiddlewareRegistration> accumulated adopter / package middleware */
    private array $customMiddlewares = [];

    /**
     * @param class-string $messageClass
     * @param class-string $handlerClass
     */
    public function registerHandler(string $messageClass, string $handlerClass): self
    {
        $this->handlers[$messageClass] = $handlerClass;

        return $this;
    }

    /** @param class-string $boundClass */
    public function bindConnection(string $boundClass, string $connectionName): self
    {
        $this->bindings[$boundClass] = $connectionName;

        return $this;
    }

    /**
     * Register a custom Middleware impl into the canonical pipeline.
     *
     * Adopters and downstream packages (e.g. `nexus-ddd-aggregate`'s
     * `OneAggregatePerCommandMiddleware`) ship their own Middleware impl and
     * use this method to splice it at the correct position. The canonical
     * PipelineStage enum stays locked; custom middleware references stages
     * by name to declare their insertion point.
     *
     * Semantics:
     *   `$before === null`            → append after the last canonical stage (after SpanClose).
     *   `$before === PipelineStage::X` → insert immediately before the canonical X middleware.
     *   Multiple registrations sharing the same `$before` preserve registration order
     *   (first-registered runs first).
     *
     * The actual splice happens inside the downstream pipeline assembler (Phase 13's
     * Sync*Bus constructors). BusBuilder only accumulates the registration records;
     * `BusBuildResult::$customMiddlewares` exposes them in registration order.
     */
    #[NoDiscard('withMiddleware returns the builder — chain or assign')]
    public function withMiddleware(Middleware $middleware, ?PipelineStage $before = null): self
    {
        $this->customMiddlewares[] = new CustomMiddlewareRegistration($middleware, $before);

        return $this;
    }

    public function build(
        Profile $profile,
        bool $hasValidator,
        bool $hasDecider,
        Composite $routing,
    ): BusBuildResult {
        $entries = [];

        foreach ($this->handlers as $messageClass => $handlerClass) {
            $resolved = $this->reflectHandler($handlerClass);

            if ($resolved->attribute(Validate::class)->isSome() && !$hasValidator) {
                throw MissingValidatorException::forHandler($handlerClass);
            }

            if ($resolved->attribute(Authorize::class)->isSome() && !$hasDecider) {
                throw MissingAuthorizationDeciderException::forHandler($handlerClass);
            }

            $entries[$messageClass] = $resolved;
        }

        $inProcessValidator = new InProcessSameDbBootValidator($this->bindings);
        $inProcessValidator->validate($this->handlers);

        $routing->validate(array_keys($this->handlers));

        return new BusBuildResult(
            new HandlerAttributeIndex($entries),
            $this->handlers,
            $this->customMiddlewares,
        );
    }

    /** @param class-string $handlerClass */
    private function reflectHandler(string $handlerClass): ResolvedAttributesEntry
    {
        $reflection = new ReflectionClass($handlerClass);
        $attributes = [];
        $authorizeBeforeValidate = false;
        $idempotencyOptedOut = false;

        foreach ($reflection->getAttributes() as $attr) {
            $attributes[$attr->getName()] = $attr->newInstance();
        }

        foreach ($reflection->getMethods() as $method) {
            foreach ($method->getAttributes() as $attr) {
                $attributes[$attr->getName()] = $attr->newInstance();
            }
        }

        $authorize = $attributes[Authorize::class] ?? null;

        if ($authorize !== null && $authorize->before === 'validation') {
            $authorizeBeforeValidate = true;
        }

        $idempotent = $attributes[Idempotent::class] ?? null;

        if ($idempotent !== null && $idempotent->off) {
            $idempotencyOptedOut = true;
        }

        return new ResolvedAttributesEntry(
            $handlerClass,
            $attributes,
            $authorizeBeforeValidate,
            $idempotencyOptedOut,
        );
    }
}
```

```php
namespace Monadial\Nexus\Ddd\Bus\Routing;

/**
 * @psalm-api
 * @psalm-immutable
 */
final readonly class BusBuildResult
{
    /**
     * @param array<class-string, class-string> $handlerMap
     * @param list<CustomMiddlewareRegistration> $customMiddlewares
     */
    public function __construct(
        public HandlerAttributeIndex $index,
        public array $handlerMap,
        public array $customMiddlewares,
    ) {}
}
```

```php
namespace Monadial\Nexus\Ddd\Bus\Routing;

use Monadial\Nexus\Ddd\Bus\Middleware\Middleware;
use Monadial\Nexus\Ddd\Bus\Middleware\PipelineStage;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Splice record for a single adopter / package-supplied Middleware.
 *
 * Carried in `BusBuildResult::$customMiddlewares` in registration order.
 * Phase 13's Sync*Bus constructors walk the list and splice each entry
 * into the canonical 14-stage list at the position named by `$before`.
 */
final readonly class CustomMiddlewareRegistration
{
    public function __construct(
        public Middleware $middleware,
        public ?PipelineStage $before,
    ) {}
}
```

- [ ] **Step 4: Tests cover**
  - Register handler, build, verify index lookup.
  - Register handler with `#[Validate]`, no validator → `MissingValidatorException`.
  - Register handler with `#[Authorize]`, no decider → `MissingAuthorizationDeciderException`.
  - Register `#[InProcess]` handler with mismatched conn binding → `InProcessConnectionMismatchException`.
  - Register handlers that two routing strategies resolve differently → `DuplicateRoutingException`.
  - Register handler with `#[Authorize(before: 'validation')]` → entry's `authorizeBeforeValidate` is true.
  - **`withMiddleware` registration (H13):**
    - Register a single custom middleware with no `before:` → `BusBuildResult::$customMiddlewares` has one entry with `before === null`.
    - Register with `before: PipelineStage::Validation` → entry's `before` is that case.
    - Register two custom middlewares targeting the same `$before` → the result list preserves registration order.
    - Register one custom middleware with `before: PipelineStage::Handler` and one with `before: null` → both entries present, in registration order.
    - `withMiddleware` is chainable: `$builder->withMiddleware($a)->withMiddleware($b)->build(...)` works.
    - `#[NoDiscard]` is enforced on the method (verify via reflection or rely on Psalm).

- [ ] **Step 5: Run tests + Psalm + PHPCS clean**
- [ ] **Step 6: Commit**

```bash
git commit -m "feat(ddd-bus): BusBuilder boot orchestrator + HandlerAttributeIndex (cached reflection) + InProcessSameDbBootValidator (replaces v1 runtime no-op middleware) + per-handler authorize-before-validate flip baked into cached pipeline + withMiddleware(...) adopter / package extension hook"
```

---

## Phase 12b — `BusRegistry` + `CommandRouter` + boot-time profile validation

**Files:**
- Create: `packages/nexus-ddd-bus/src/Routing/BusRegistry.php`
- Create: `packages/nexus-ddd-bus/src/Routing/CommandRouter.php`
- Tests

**Locks:**
- `BusRegistry` holds `array<busName, CommandBus>` and parallel maps for `QueryBus` / `EventBus`. At construction, validates Profile×bus availability per the ruleset:
  - Sync profile: only sync impls allowed.
  - Async profile: sync + async impls allowed.
  - Actor profile: any impl allowed.
- `CommandRouter` wraps `BusRegistry` + `Composite`; exposes `routeFor(class-string): CommandBus`.

- [ ] **Step 1: TDD `BusRegistry`**

```php
namespace Monadial\Nexus\Ddd\Bus\Routing;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Bus\Exception\BusNameNotRegisteredException;
use Monadial\Nexus\Ddd\Bus\Exception\BusNotAvailableInProfileException;
use Monadial\Nexus\Ddd\Bus\Profile\Profile;
use Monadial\Nexus\Ddd\Messaging\Bus\CommandBus;
use Monadial\Nexus\Ddd\Messaging\Bus\EventBus;
use Monadial\Nexus\Ddd\Messaging\Bus\QueryBus;

/**
 * @psalm-api
 *
 * Immutable map name → bus impl. Built once via the BusBuilder pipeline
 * after all handlers are registered. Validates Profile × bus-impl
 * compatibility at construction.
 */
final readonly class BusRegistry
{
    /**
     * @param array<string, CommandBus> $commandBuses
     * @param array<string, QueryBus> $queryBuses
     * @param array<string, EventBus> $eventBuses
     */
    public function __construct(
        public Profile $profile,
        public array $commandBuses,
        public array $queryBuses,
        public array $eventBuses,
    ) {}

    /** @return Option<CommandBus> */
    public function command(string $name): Option
    {
        return Option::fromNullable($this->commandBuses[$name] ?? null);
    }

    /** @return list<string> */
    public function commandNames(): array
    {
        return array_keys($this->commandBuses);
    }

    /**
     * @param iterable<class-string, RoutingResolution> $resolutions  message-class → resolved-route
     * @throws BusNameNotRegisteredException
     * @throws BusNotAvailableInProfileException
     */
    public function validateRoutes(iterable $resolutions): void
    {
        foreach ($resolutions as $messageClass => $resolution) {
            if (!isset($this->commandBuses[$resolution->busName])) {
                throw BusNameNotRegisteredException::for(
                    $resolution->busName,
                    $this->commandNames(),
                );
            }

            $bus = $this->commandBuses[$resolution->busName];

            if (!$this->profileAllows($bus)) {
                throw BusNotAvailableInProfileException::for(
                    $resolution->busName,
                    $this->profile,
                    $messageClass,
                );
            }
        }
    }

    private function profileAllows(CommandBus $bus): bool
    {
        return true;
    }
}
```

(The exact `profileAllows` shape depends on whether bus impls expose a `profile()` accessor — adapt at execution time. For P0 with only `SyncCommandBus`, the check is trivially true.)

- [ ] **Step 2: TDD `CommandRouter`**

```php
namespace Monadial\Nexus\Ddd\Bus\Routing;

use Monadial\Nexus\Ddd\Bus\Exception\BusNameNotRegisteredException;
use Monadial\Nexus\Ddd\Messaging\Bus\CommandBus;

final class CommandRouter
{
    public function __construct(
        private readonly BusRegistry $registry,
        private readonly RoutingStrategy $strategy,
    ) {}

    /** @param class-string $messageClass */
    public function routeFor(string $messageClass): CommandBus
    {
        $resolution = $this->strategy->resolve($messageClass)->get();

        return $this->registry->command($resolution->busName)->getOrElseThrow(
            fn() => BusNameNotRegisteredException::for(
                $resolution->busName,
                $this->registry->commandNames(),
            ),
        );
    }
}
```

- [ ] **Step 3: Run tests + Psalm + PHPCS clean**
- [ ] **Step 4: Commit**

```bash
git commit -m "feat(ddd-bus): BusRegistry (immutable; Profile × bus-impl validation) + CommandRouter"
```

---

## Phase 13 — `SyncCommandBus`, `SyncQueryBus`, `SyncEventBus`

**Files:**
- Create: `packages/nexus-ddd-bus/src/Bus/SyncCommandBus.php`
- Create: `packages/nexus-ddd-bus/src/Bus/SyncQueryBus.php`
- Create: `packages/nexus-ddd-bus/src/Bus/SyncEventBus.php`
- Tests

**Locks (H2 + H5 + H10 + H13):**
- `SyncCommandBus implements CommandBus` (canonical messaging interface — has both `dispatchCommand` and `tryDispatch`) AND `EnvelopedCommandBus`. NO `RichCommandBus` extension interface.
- Constructor (locked, 4 args): `(BusRegistry $registry, HandlerAttributeIndex $index, MiddlewarePipeline $pipeline, Profile $profile)`. All bus-internal collaborators (clock, logger, metrics, validator, decider, idempotency store, locator, outbox, backoff) flow through the pipeline closure passed in `MiddlewarePipeline::$core` at builder time.
- `tryDispatch` propagates `BusInvariantException` (per H5) — does NOT lift to `Either::left`.
- `RetryBudgetExhaustedException` IS caught and lifted to `Either::left` (it's a runtime-retryable failure, not a boot invariant).
- **Pipeline assembly (per H13):** the `MiddlewarePipeline` passed in to the constructor is pre-assembled by the composition root. The assembler builds the canonical 14-stage list (one instance per `PipelineStage` case, constructed from the registered slots), then walks `BusBuildResult::$customMiddlewares` (returned by `BusBuilder::build()`) and splices each `CustomMiddlewareRegistration` at the position named by its `$before` field. Custom registrations with `$before === null` append after `PipelineStage::SpanClose`. Multiple registrations sharing the same `$before` are inserted in registration order. Phase 13's tests cover a canonical-only pipeline + a canonical-with-custom-splice pipeline using a `RecordingMiddleware` fixture to verify execution order.

- [ ] **Step 1: TDD `SyncCommandBus`**

```php
namespace Monadial\Nexus\Ddd\Bus\Bus;

use Fp\Functional\Either\Either;
use Monadial\Nexus\Ddd\Bus\Exception\BusInvariantException;
use Monadial\Nexus\Ddd\Bus\Middleware\MiddlewarePipeline;
use Monadial\Nexus\Ddd\Bus\Profile\Profile;
use Monadial\Nexus\Ddd\Bus\Routing\BusRegistry;
use Monadial\Nexus\Ddd\Bus\Routing\HandlerAttributeIndex;
use Monadial\Nexus\Ddd\Messaging\Bus\CommandBus;
use Monadial\Nexus\Ddd\Messaging\Bus\EnvelopedCommandBus;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Marker\Accepted;
use Monadial\Nexus\Ddd\Messaging\Message\Command;
use Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata;
use Override;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * @psalm-api
 *
 * Synchronous command bus. Implements the canonical CommandBus interface
 * directly (both dispatchCommand and tryDispatch — no Rich* extension
 * needed since messaging upstream has tryDispatch on canonical).
 *
 * The pipeline is built once at boot (per handler) by BusBuilder and
 * looked up via the index. Constructor takes 4 args — internal
 * collaborators are baked into the cached pipeline.
 */
final class SyncCommandBus implements CommandBus, EnvelopedCommandBus
{
    public function __construct(
        private readonly BusRegistry $registry,
        private readonly HandlerAttributeIndex $index,
        private readonly MiddlewarePipeline $pipeline,
        private readonly Profile $profile,
        private readonly ClockInterface $clock,
    ) {}

    #[Override]
    public function dispatchCommand(Command $command): void
    {
        $this->tryDispatch($command)->fold(
            onLeft: static fn(Throwable $e) => throw $e,
            onRight: static fn(Accepted $_): null => null,
        );
    }

    #[Override]
    public function tryDispatch(Command $command): Either
    {
        $envelope = new Envelope($command, MessageMetadata::root($this->clock));

        try {
            $this->pipeline->dispatch($envelope);

            return Either::right(new Accepted());
        } catch (BusInvariantException $e) {
            throw $e;
        } catch (Throwable $e) {
            return Either::left($e);
        }
    }

    #[Override]
    public function dispatchEnveloped(Envelope $envelope): void
    {
        $this->pipeline->dispatch($envelope);
    }
}
```

- [ ] **Step 2: TDD `SyncQueryBus`** — analogous; implements canonical `QueryBus` (has both `dispatchQuery` and `tryAsk`) + `EnvelopedQueryBus`. No idempotency middleware (queries inherently idempotent). No event drain.

- [ ] **Step 3: TDD `SyncEventBus`** — fan-out to N subscribers. For `Profile::Sync`, all subscribers run in-tx.

- [ ] **Step 4: Run tests + Psalm + PHPCS clean**
- [ ] **Step 5: Commit**

```bash
git commit -m "feat(ddd-bus): SyncCommandBus (implements canonical messaging\CommandBus + EnvelopedCommandBus directly — no Rich* extension since messaging upstream has tryDispatch) + SyncQueryBus + SyncEventBus; tryDispatch propagates BusInvariantException, lifts other Throwable to Either::left; constructor locked to 4 args"
```

---

## Phase 14 — `RoutesShowCommand` service + `Cli\Command` interface

(Phase 14 of v1 — `IdempotencyKeyResolver` integration — was folded into Phase 10c per panel L3. This is the renumbered phase.)

**Files:**
- Create: `packages/nexus-ddd-bus/src/Cli/Command.php` (interface)
- Create: `packages/nexus-ddd-bus/src/Cli/RoutesShowCommand.php`
- Tests

**Lock M4:** the `bin/ddd` shell shim moves to `nexus-ddd-cli` (TBD) or `nexus-app` adapter packages. This package ships ONLY the service. No `bin/` directory. No symfony/console dep.

- [ ] **Step 1: TDD `Cli\Command` interface**

```php
namespace Monadial\Nexus\Ddd\Bus\Cli;

/**
 * @psalm-api
 *
 * Minimal command shape. Adapter packages (nexus-ddd-cli — TBD, or
 * nexus-app) supply the shell shim that wires argv → Command::run.
 */
interface Command
{
    /** @param list<string> $args */
    public function run(array $args): string;
}
```

- [ ] **Step 2: TDD `RoutesShowCommand`**

```php
namespace Monadial\Nexus\Ddd\Bus\Cli;

use Monadial\Nexus\Ddd\Bus\Routing\BusRegistry;
use Monadial\Nexus\Ddd\Bus\Routing\RoutingStrategy;
use Override;

/**
 * @psalm-api
 *
 * Service shape for the routes-show CLI subcommand. The runner package
 * (nexus-ddd-cli — TBD) supplies the argv parser and the shell shim;
 * this package ships only the service.
 */
final class RoutesShowCommand implements Command
{
    public function __construct(
        private readonly BusRegistry $registry,
        private readonly RoutingStrategy $strategy,
    ) {}

    #[Override]
    public function run(array $args): string
    {
        if ($args === []) {
            return $this->renderAll();
        }

        return $this->renderOne($args[0]);
    }

    private function renderAll(): string
    {
        $output = "Registered command buses:\n";

        foreach ($this->registry->commandNames() as $name) {
            $output .= sprintf("  %s\n", $name);
        }

        return $output;
    }

    private function renderOne(string $messageClass): string
    {
        $resolution = $this->strategy->resolve($messageClass)->get();

        return sprintf(
            "%s → bus `%s` (resolved by %s)\n",
            $messageClass,
            $resolution->busName,
            $resolution->displayName(),
        );
    }
}
```

- [ ] **Step 3: Run tests + Psalm + PHPCS clean**
- [ ] **Step 4: Commit**

```bash
git commit -m "feat(ddd-bus): Cli\\Command interface + RoutesShowCommand service (no shell shim — bin/ddd deferred to nexus-ddd-cli adapter package)"
```

---

## Phase 15 — Smoke tests (full-pipeline end-to-end)

**Files:**
- Create: `packages/nexus-ddd-bus/tests/Unit/Smoke/Fixtures/PlaceOrder.php`
- Create: `packages/nexus-ddd-bus/tests/Unit/Smoke/Fixtures/PlaceOrderHandler.php`
- Create: `packages/nexus-ddd-bus/tests/Unit/Smoke/Fixtures/CancelOrder.php`
- Create: `packages/nexus-ddd-bus/tests/Unit/Smoke/Fixtures/CancelOrderHandler.php`
- Create: `packages/nexus-ddd-bus/tests/Unit/Smoke/PlaceOrderEndToEndSmokeTest.php`
- Create: `packages/nexus-ddd-bus/tests/Unit/Smoke/ValidationFailureSmokeTest.php`
- Create: `packages/nexus-ddd-bus/tests/Unit/Smoke/AuthorizationDeniedSmokeTest.php`
- Create: `packages/nexus-ddd-bus/tests/Unit/Smoke/IdempotencyShortCircuitSmokeTest.php`
- Create: `packages/nexus-ddd-bus/tests/Unit/Smoke/IdempotencyTwoPhaseInsideTxSmokeTest.php` (new — per B2)
- Create: `packages/nexus-ddd-bus/tests/Unit/Smoke/OccRetryRetriesAndRecoversSmokeTest.php`
- Create: `packages/nexus-ddd-bus/tests/Unit/Smoke/OccRetryActorWrapsAsInvariantSmokeTest.php`
- Create: `packages/nexus-ddd-bus/tests/Unit/Smoke/OccRetryBudgetExhaustedSmokeTest.php` (new — per H12)
- Create: `packages/nexus-ddd-bus/tests/Unit/Smoke/CausationDepthExceededSmokeTest.php`
- Create: `packages/nexus-ddd-bus/tests/Unit/Smoke/IdempotencyHttpHeaderBridgeSmokeTest.php` (new — per L6)
- Create: `packages/nexus-ddd-bus/tests/Unit/Smoke/CausationChainOnEmittedEventsSmokeTest.php` (new — per M7)
- Create: `packages/nexus-ddd-bus/tests/Unit/Smoke/BusInvariantExceptionPropagatesThroughTryDispatchSmokeTest.php` (new — per H5)
- Create: `packages/nexus-ddd-bus/tests/Unit/Performance/SmokeBenchmarkTest.php` (new — per L5)

- [ ] **Step 1: TDD `PlaceOrderEndToEndSmokeTest`** — happy path. Dispatch → handler runs, metrics emitted, idempotency token committed (under non-Sync profile), event drained.

- [ ] **Step 2: TDD `ValidationFailureSmokeTest`** — Validator returns Violations → `tryDispatch` → `Either::left(ValidationFailedException)`.

- [ ] **Step 3: TDD `AuthorizationDeniedSmokeTest`** — decider throws → `tryDispatch` → `Either::left(AccessDeniedException)`.

- [ ] **Step 4: TDD `IdempotencyShortCircuitSmokeTest`** — same idempotency key dispatched twice; second short-circuits.

- [ ] **Step 5: TDD `IdempotencyTwoPhaseInsideTxSmokeTest` (new — per B2)** — verify ordering: handler success → IdempotencyCommit (`markCompleted`) → EventDrain (`flush`). The order matters: a handler that throws AFTER `markCompleted` but BEFORE `flush` should NOT have a committed reservation, because the TX rolls back. Test uses a fixture transactional outbox that rolls back on flush failure.

- [ ] **Step 6: TDD `OccRetryRetriesAndRecoversSmokeTest`** — handler throws OCC once, succeeds on second attempt; assert two attempts.

- [ ] **Step 7: TDD `OccRetryActorWrapsAsInvariantSmokeTest`** — `Profile::Actor` + OCC → `Either::left(ActorWriterInvariantViolation)`.

- [ ] **Step 8: TDD `OccRetryBudgetExhaustedSmokeTest` (new — per H12)** — repeated OCC throws past budget; assert metrics emitted (`ddd.command.retry_exhausted`), WARN log line emitted, `tryDispatch` → `Either::left(RetryBudgetExhaustedException)`.

- [ ] **Step 9: TDD `CausationDepthExceededSmokeTest`** — synthetic envelope with `headers[nexus.causation.depth] = 32`; dispatch → `CausationDepthExceededException`.

- [ ] **Step 10: TDD `IdempotencyHttpHeaderBridgeSmokeTest` (new — per L6)** — message has no `#[IdempotencyKey]` attribute; envelope metadata has `headers[nexus.idempotency-key]`; dispatch twice → second short-circuits.

- [ ] **Step 11: TDD `CausationChainOnEmittedEventsSmokeTest` (new — per M7)** — dispatch a command that emits two events; verify each emitted event's metadata has `causationId = sourceCommand.messageId` and `headers[nexus.causation.depth] = sourceCommand.depth + 1`.

- [ ] **Step 12: TDD `BusInvariantExceptionPropagatesThroughTryDispatchSmokeTest` (new — per H5)** — bus configured with `MissingValidatorException` thrown at boot; dispatch via `tryDispatch` propagates the exception (does NOT lift to `Either::left`).

- [ ] **Step 13: TDD `SmokeBenchmarkTest` (new — per L5)** — 10000 dispatches of a no-op handler in <50ms wall-clock. The test guards against accidental O(n²) in pipeline composition or attribute reflection.

- [ ] **Step 14: Run all smoke tests; Psalm + PHPCS clean**
- [ ] **Step 15: Commit**

```bash
git commit -m "test(ddd-bus): smoke tests covering full-pipeline (place-order happy path, validation failure, authorization denied, idempotency two-phase inside TX, idempotency short-circuit, OCC retry recovers + budget exhausted + actor-mode invariant, causation depth + chain on emitted events, BusInvariantException propagates through tryDispatch, HTTP-header bridge for idempotency-key, smoke perf 10000<50ms)"
```

---
## Phase 16 — Psalm rules in `nexus-psalm` (Phase 17 of v1; renumbered)

**Files (in `nexus-psalm` package):**
- Create: `packages/nexus-psalm/src/Hook/Bus/CommandHandlerReturnTypeRule.php`
- Create: `packages/nexus-psalm/src/Hook/Bus/CommandReturnValueIgnoredRule.php`
- Create: `packages/nexus-psalm/src/Hook/Bus/ValidatedCommandReadonlyRule.php`
- Create: `packages/nexus-psalm/src/Hook/Bus/IdempotencyKeyFieldExistsRule.php`
- Create: `packages/nexus-psalm/src/Hook/Bus/AuthorizeBeforeValidationRule.php`
- Create: `packages/nexus-psalm/src/Hook/Bus/UnguardedExternalSideEffectRule.php`
- Create: `packages/nexus-psalm/src/Hook/Bus/MiddlewareOrderingRule.php`
- Create matching `Issue/` classes
- Create fixtures + tests for each
- Modify: `packages/nexus-psalm/src/Plugin.php` — register the 7 new rules

**Per panel H11: each rule specifies hook + AST + Issue + fixture pair.**

### Rule 1: `CommandHandlerReturnTypeRule`

- **Hook:** `Psalm\Plugin\EventHandler\AfterClassLikeAnalysisInterface`
- **AST node inspected:** `Stmt\ClassMethod` (handler methods declared in classes implementing `Monadial\Nexus\Ddd\Messaging\Handler\CommandHandler` or attributed `#[Handler]`)
- **Issue class:** `Monadial\Nexus\Psalm\Issue\Bus\CommandHandlerNonVoidReturn`
- **Emitted message:** `"Command handler %s::%s declared return type %s; commands are pure CQS — handlers MUST declare ': void'."`
- **Fixture pair:**
  - Triggering: `class BadHandler implements CommandHandler { public function __invoke(Cmd $c): string { return ''; } }`
  - Clean: `class GoodHandler implements CommandHandler { public function __invoke(Cmd $c): void {} }`

- [ ] **Step 1: TDD CommandHandlerReturnTypeRule**

### Rule 2: `CommandReturnValueIgnoredRule`

- **Hook:** `Psalm\Plugin\EventHandler\AfterMethodCallAnalysisInterface`
- **AST node inspected:** `Expr\MethodCall` where the callee is `Monadial\Nexus\Ddd\Messaging\Bus\CommandBus::dispatchCommand`
- **Issue class:** `Monadial\Nexus\Psalm\Issue\Bus\CommandReturnValueAssigned`
- **Emitted message:** `"$bus->dispatchCommand() returns void; assigning the return value to a variable is dead code."`
- **Fixture pair:**
  - Triggering: `$x = $bus->dispatchCommand($cmd);`
  - Clean: `$bus->dispatchCommand($cmd);`

- [ ] **Step 2: TDD CommandReturnValueIgnoredRule**

### Rule 3: `ValidatedCommandReadonlyRule`

- **Hook:** `Psalm\Plugin\EventHandler\AfterClassLikeAnalysisInterface`
- **AST node inspected:** `Stmt\Class_` whose handler method has `#[Validate]` AND inspects the command-class parameter
- **Issue class:** `Monadial\Nexus\Psalm\Issue\Bus\ValidatedCommandNotReadonly`
- **Emitted message:** `"Command class %s is referenced by a #[Validate] handler but is not readonly. Validated commands MUST be readonly."`
- **Fixture pair:**
  - Triggering: `class CmdNotReadonly { public string $x; }` referenced by a `#[Validate]` handler
  - Clean: `readonly class CmdReadonly { public function __construct(public string $x) {} }`

- [ ] **Step 3: TDD ValidatedCommandReadonlyRule**

### Rule 4: `IdempotencyKeyFieldExistsRule`

- **Hook:** `Psalm\Plugin\EventHandler\AfterClassLikeAnalysisInterface`
- **AST node inspected:** `Stmt\Class_` with `#[IdempotencyKey(field: '...')]` attribute
- **Issue class:** `Monadial\Nexus\Psalm\Issue\Bus\IdempotencyKeyFieldMissing`
- **Emitted message:** `"#[IdempotencyKey(field: '%s')] on %s names a property that does not exist on the class (or whose type is not string)."`
- **Fixture pair:**
  - Triggering: `#[IdempotencyKey(field: 'missingField')] readonly class Cmd { public function __construct(public string $other) {} }`
  - Clean: `#[IdempotencyKey(field: 'clientRequestId')] readonly class Cmd { public function __construct(public string $clientRequestId) {} }`

- [ ] **Step 4: TDD IdempotencyKeyFieldExistsRule**

### Rule 5: `AuthorizeBeforeValidationRule`

- **Hook:** `Psalm\Plugin\EventHandler\AfterClassLikeAnalysisInterface`
- **AST node inspected:** `Stmt\ClassMethod` with `#[Authorize(before: '...')]`
- **Issue class:** `Monadial\Nexus\Psalm\Issue\Bus\AuthorizeBeforeStageUnknown`
- **Emitted message:** `"#[Authorize(before: '%s')] on %s::%s names a stage that is not in PipelineStage. Valid stages: [%s]."`
- **Fixture pair:**
  - Triggering: `#[Authorize(policy: 'order.cancel', before: 'validashion')] public function handle(...): void {}`
  - Clean: `#[Authorize(policy: 'order.cancel', before: 'validation')] public function handle(...): void {}`

- [ ] **Step 5: TDD AuthorizeBeforeValidationRule**

### Rule 6: `UnguardedExternalSideEffectRule`

- **Hook:** `Psalm\Plugin\EventHandler\AfterMethodCallAnalysisInterface`
- **AST node inspected:** `Expr\MethodCall` whose callee class matches a configurable allow-list (e.g., `Mailer`, `HttpClient`, `PaymentGateway`) and whose enclosing method is a command handler
- **Issue class:** `Monadial\Nexus\Psalm\Issue\Bus\UnguardedExternalSideEffect`
- **Emitted message (warning, not error):** `"Handler %s::%s calls external side-effect API %s::%s but the command class %s has no #[IdempotencyKey] attribute. Consider adding one to make redelivery safe."`
- **Fixture pair:**
  - Triggering: handler calls `Mailer::send()`, command has no `#[IdempotencyKey]`
  - Clean: same handler, command has `#[IdempotencyKey(field: 'requestId')]`

- [ ] **Step 6: TDD UnguardedExternalSideEffectRule**

### Rule 7: `MiddlewareOrderingRule`

- **Hook:** `Psalm\Plugin\EventHandler\AfterFunctionLikeAnalysisInterface` (or `AfterMethodCallAnalysisInterface` — depends on adopter API shape; pick at execution time)
- **AST node inspected:** calls to a hypothetical `MiddlewarePipelineBuilder::add(stage: '...')` or similar; or simply scans `#[Authorize(before: '...')]` (overlaps with Rule 5 — keep distinct concerns)
- **Issue class:** `Monadial\Nexus\Psalm\Issue\Bus\MiddlewareStageUnknown`
- **Emitted message:** `"Stage name '%s' does not match any case of PipelineStage. Valid stages: [%s]."`
- **Fixture pair:**
  - Triggering: adopter middleware tagged with `before: 'validashion'`
  - Clean: tagged with `before: 'validation'`

- [ ] **Step 7: TDD MiddlewareOrderingRule**

- [ ] **Step 8: Register all 7 rules in `Plugin.php`**

- [ ] **Step 9: Run plugin testsuite; all rules pass**

- [ ] **Step 10: Commit each rule as its own commit (per messaging plugin convention)**

```bash
git commit -m "feat(psalm): CommandHandlerReturnTypeRule (void return required for command handlers)"
```

---

## Phase 17 — Fitness tests (Phase 18 of v1; renumbered)

**Files:**
- Create: `packages/nexus-ddd-bus/tests/Unit/Fitness/PackageDependencyFitnessTest.php`
- Create: `packages/nexus-ddd-bus/tests/Unit/Fitness/ForbiddenImportsFitnessTest.php`
- Create: `packages/nexus-ddd-bus/tests/Unit/Fitness/AbstractClassReadonlyOrFinalFitnessTest.php`

`PackageDependencyFitnessTest` walks `src/`, asserts no `use` statements outside the allowed set: `fp4php/functional`, `nexus-actors/ddd-core`, `nexus-actors/ddd-messaging`, `psr/log`, `psr/event-dispatcher`, `psr/clock`, `psr/container`, internal bus package. Forbids `nexus-actors/ddd-aggregate`, `nexus-persistence*`, `Symfony\*`, `Doctrine\*`, `Monolog\*`.

`ForbiddenImportsFitnessTest` mirrors aggregate package's fitness test.

`AbstractClassReadonlyOrFinalFitnessTest` verifies all classes in `src/` are either `abstract` or `final`.

- [ ] **Step 1–3: TDD each fitness test**
- [ ] **Step 4: Commit**

```bash
git commit -m "test(ddd-bus): fitness functions (package deps, forbidden imports, final-or-abstract)"
```

---

## Phase 18 — Documentation pass (Phase 19 of v1; renumbered)

**Files:**
- Create: `packages/nexus-ddd-bus/README.md`
- Verify: every public `interface` / `class` has a `@psalm-api` docblock with a 2-paragraph description.

The README MUST:
- Reference §25.6 known limitations (causation-chain integrity across writer-id changes; snapshot-vs-event-store transactional divergence — though those are aggregate-side, the bus README mentions cross-package implications).
- Reference §11.2 — the `InProcessSameDbBootValidator` is boot-time; runtime introspection deferred to aggregate package.
- Document the **sync-confirmation cookbook** (per umbrella spec §8.6.1).
- Document the **handler-may-read-MessageContext** rule (per umbrella spec §7.3).
- Document `BusInvariantException` propagation: adopters wrap composition-root code in `try/catch (BusBootException)` to handle boot-time misconfiguration; controllers see runtime exceptions via `Either::left` from `tryDispatch`.
- Document the **Sensitive payload-redaction policy** (per panel M5): `#[Sensitive]` on properties; default-deny payload-at-DEBUG.
- Document the **HTTP-header bridge for idempotency-key**: adapter packages (e.g., nexus-ddd-symfony) populate `MessageMetadata::$headers['nexus.idempotency-key']` from the `X-Nexus-Idempotency-Key` HTTP header.
- Document the **out-of-scope deferrals** clearly:
  - `AsyncCommandBus`/`AsyncEventBus` → `nexus-ddd-async` (P3)
  - `ActorCommandBus` → `nexus-ddd-actor` (P4)
  - `OutboxEventBus` → `nexus-ddd-outbox` (P3)
  - DB-backed `IdempotencyStore` impl → `nexus-ddd-bus-idempotency-doctrine`
  - Symfony bundle integration → `nexus-ddd-symfony` (P4)
  - OpenTelemetry SDK adapter → `nexus-ddd-otel-adapter`
  - SELECT FOR UPDATE SKIP LOCKED outbox locking discipline → `nexus-ddd-outbox`
  - PM compensation / `CommandEmissionFailed` system event → `nexus-ddd-process-manager` (P2)
  - `bin/ddd` shell shim → `nexus-ddd-cli` (TBD)

- [ ] **Step 1: Write README**
- [ ] **Step 2: Docblock sweep**
- [ ] **Step 3: Commit**

```bash
git commit -m "docs(ddd-bus): README + class docblock pass; sync-confirmation cookbook + handler-may-read-MessageContext + Sensitive redaction + HTTP-header bridge + clear deferrals"
```

---

## Phase 19 — Final CI sweep + PR (Phase 20 of v1; renumbered)

- [ ] **Step 1: Full pipeline**

```bash
docker compose exec -T php composer dump-autoload --quiet
docker compose exec -T php vendor/bin/psalm --no-cache
docker compose exec -T php vendor/bin/phpunit --testsuite=unit
docker compose exec -T php vendor/bin/phpunit --testsuite=psalm
docker compose exec -T php vendor/bin/phpcs packages/nexus-ddd-bus/
docker compose exec -T php vendor/bin/php-cs-fixer fix --dry-run packages/nexus-ddd-bus/src
docker compose exec -T php vendor/bin/deptrac
```

All clean.

- [ ] **Step 2: Coverage gate (per panel L4)** — assert 90% method-coverage minimum per CLAUDE.md CI pipeline policy.

```bash
docker compose exec -T php vendor/bin/phpunit --testsuite=unit --coverage-text --coverage-filter=packages/nexus-ddd-bus/src
```

Confirm method-coverage threshold ≥ 90%.

- [ ] **Step 3: Push branch + open PR**

```bash
git push -u origin feat/nexus-ddd-bus
gh pr create --title "feat(ddd): add nexus-ddd-bus package" --body "$(cat <<'BODY'
## Summary

Adds `nexus-ddd-bus` — the central dispatch fabric for the Nexus DDD framework. P0 scope:

- `SyncCommandBus`, `SyncQueryBus`, `SyncEventBus` impls (per umbrella spec §8) implementing the canonical messaging bus interfaces directly (both `dispatchCommand`/`tryDispatch`, etc. — no Rich* extension since messaging upstream provides both methods on the canonical interface).
- Canonical 14-stage middleware pipeline (causation → OTel-noop → logging-start → metrics-start → validation → authorization → idempotency-reserve → OCC retry → handler → idempotency-commit → event-drain → metrics-end → logging-end → span-close), per §8.5.1. Idempotency split into Reserve (outer) + Commit (inner) so `markCompleted` runs INSIDE the handler TX.
- `BusBuilder` + `BusRegistry` + `HandlerAttributeIndex` + `CommandRouter` + composite routing (`ExplicitOnly` / `AttributeBased` / `NamespacePattern` / `Composite`), per §8.2. Per-handler pipeline cache bakes the `#[Authorize(before: 'validation')]` flip at boot.
- `Profile` enum (Sync / Async / Actor) + boot-time profile×routing validation, per §8.2.1.
- All 4 slot interfaces — `Validator`, `AuthorizationDecider`, `IdempotencyStore`, `MetricsCollector` — with default impls.
- `Principal` interface; `Option<Principal>` on validation/authorization contexts (no nullable principal).
- `IdempotencyReservation` interface + each-store concrete reservation; `tryReserve / markCompleted / release` two-phase contract; `ttl(): Duration` for boot validation.
- 8 attributes — `Validate`, `Authorize`, `OnBus`, `IdempotencyKey`, `Idempotent`, `InProcess`, `Handler` (renamed from `CommandHandler` to avoid collision with the messaging marker interface), `Sensitive` (payload redaction).
- 14+ exception classes — `BusException` root + `BusBootException` / `BusRuntimeException` intermediates + `BusInvariantException` + `RetryableFailure` markers. `tryDispatch` propagates `BusInvariantException`; lifts other Throwable to `Either::left`.
- Host-aware OCC retry middleware: under `Profile::Sync`, retries per `BackoffStrategy`; under `Profile::Actor`, wraps `OptimisticLockException` as `ActorWriterInvariantViolation`. Retry-budget exhaustion emits `ddd.command.retry_exhausted` metric + WARN log + `RetryBudgetExhaustedException`.
- `CausationDepthExceededException` + depth-counter middleware (default cap 32) reading/writing via `MessageMetadata::$headers` (canonical `Headers` from messaging upstream).
- Causation chain: emitted events stamp `causationId = sourceCommand.messageId` and depth+1.
- 7 new Psalm rules in nexus-psalm, each with hook + AST node + Issue class + fixture pair.
- `RoutesShowCommand` service + `Cli\Command` interface (no shell shim — deferred to `nexus-ddd-cli`).
- Smoke tests covering full pipeline (incl. perf 10000<50ms) + fitness tests + comprehensive docblocks.

Reuses already-shipped from messaging upstream: `CommandBus` / `QueryBus` / `EventBus` (with `tryDispatch` / `tryAsk` / `tryPublish` on canonical) / `EnvelopedCommandBus` etc. / `Headers` value object on `MessageMetadata` / `Accepted` marker / `MessageInbox::markCompleted` (renamed from `markProcessed`) / `CommandHandler` marker interface / `MessageId` / `MessageMetadata` / `MessageContext` / `MessageContextStack` / `Outbox` / `BackoffStrategy` impls / retry policies / `TerminalFailure` / `TransientFailure` / `Envelope` (uses shipped `with(Stamp): self` and `get(class-string<Stamp>): Option<S>` API). `OptimisticLockException` from nexus-ddd-core. `AggregateRepository` consumed at runtime by handlers but NOT imported.

## Test plan

- [x] make psalm — clean
- [x] make test-unit — N tests pass; method coverage ≥ 90%
- [x] phpcs / php-cs-fixer — clean
- [x] deptrac — no boundary violations
- [x] Smoke perf — 10000 dispatches in <50ms
- [ ] Mutation testing (deferred to follow-up PR)
- [ ] Cross-package integration via nexus-ddd-async (follow-up package)

## Notes

Spec reference: docs/superpowers/specs/2026-05-06-nexus-ddd-umbrella-design.md v7 §8 + §11 + §13 + §19a + §22 + §27.1.

## Out of scope (deferred packages)

- `AsyncCommandBus`/`AsyncEventBus` → `nexus-ddd-async` (P3)
- `ActorCommandBus`/`ActorHost` → `nexus-ddd-actor` (P4)
- `OutboxEventBus` + DB-backed outbox + relay → `nexus-ddd-outbox` (P3)
- DB-backed `IdempotencyStore` → `nexus-ddd-bus-idempotency-doctrine`
- Symfony bundle integration → `nexus-ddd-symfony` (P4)
- OpenTelemetry SDK adapter → `nexus-ddd-otel-adapter`
- `bin/ddd` shell shim → `nexus-ddd-cli` (TBD)
- `OneAggregatePerCommandMiddleware` (lives in `nexus-ddd-aggregate` — contributed at runtime)

## Drift resolutions reconciled

The parallel messaging-package upstream landed `Headers` on `MessageMetadata`, `Accepted` marker, `tryDispatch`/`tryAsk`/`tryPublish` on canonical bus interfaces, and `MessageInbox::markCompleted` rename. As a result, the bus package SHEDS several v1 workarounds that are no longer needed:

- v1 `BusHeaders` + `BusHeadersStamp` → DROPPED. Bus consumes `Monadial\Nexus\Ddd\Messaging\Header\Headers` directly via `MessageMetadata::$headers`.
- v1 `RichCommandBus` / `RichQueryBus` / `RichEventBus` extension interfaces → DROPPED. Bus impls implement canonical messaging interfaces directly.
- v1 `Accepted` defined here → DROPPED. Re-exported from messaging.
- v1 `InProcessSameDbMiddleware` runtime no-op → DROPPED. Replaced by `InProcessSameDbBootValidator` (boot-time only).
BODY
)"
```

---

## Self-review checklist

Before considering the plan complete, verify:

- [ ] Every public class in the file structure has a Phase that produces it.
- [ ] Every Phase has TDD ordering with concrete code or detailed outline.
- [ ] Cross-references to v7 §8 / §11 / §13 / §19a / §22 / §27.1 are correct.
- [ ] No type/method-name drift between phases.

**Panel findings applied (B1-3, H1-12, CV1-5, M1-8, L1-9):**

- [ ] **B1** — Envelope API: middleware uses `metadata->headers->get(...)` and `withHeaders(...)` per shipped `with(Stamp)/get(class-string<Stamp>)` API; no `withStamp`/`stamp` references.
- [ ] **B2** — `IdempotencyMiddleware` SPLIT into `IdempotencyReserveMiddleware` (Phase 10c step 2) + `IdempotencyCommitMiddleware` (Phase 10c step 5); `markCompleted` runs INSIDE handler TX.
- [ ] **B3** — Exception classification: `RetryableFailure` and `TerminalFailure` marker interfaces; Reserve middleware classifies per type.
- [ ] **H1** — `InProcessSameDbMiddleware` runtime no-op DELETED; replaced by `InProcessSameDbBootValidator` (Phase 12a step 2). The exception class still ships.
- [ ] **H2** — `RichCommandBus`/`RichQueryBus`/`RichEventBus` extension interfaces DELETED. Bus impls implement canonical messaging interfaces directly with both methods.
- [ ] **H3** — `BusHeaders` + `BusHeadersStamp` DELETED. Bus consumes `Monadial\Nexus\Ddd\Messaging\Header\Headers` from messaging upstream. `HeaderKeys` constants stay in `Monadial\Nexus\Ddd\Bus\Header\HeaderKeys` (Phase 10a step 1).
- [ ] **H4** — Per-handler pipeline cache: `HandlerAttributeIndex` (Phase 12a step 1); `BusBuilder` (Phase 12a step 3) bakes `#[Authorize(before: 'validation')]` flip.
- [ ] **H5** — `BusInvariantException` marker interface (Phase 2 step 3); 5 boot exceptions implement it; `tryDispatch` propagates them (Phase 13 step 1).
- [ ] **H6** — `IdempotencyReserveMiddleware` profile-aware (Phase 10c step 2): self-disables under `Profile::Sync`.
- [ ] **H7** — `EventDrainMiddleware` profile-aware (Phase 10c step 6); causation chain stamped on emitted events.
- [ ] **H8** — `Composite::withStrategy(RoutingStrategy, ?class-string $before)` builder + `validate()` conflict detection (Phase 11 step 6).
- [ ] **H9** — Templated `Middleware<TIn, TOut>` (Phase 9 step 2).
- [ ] **H10** — `BusBuilder` promoted to Phase 12a; `BusRegistry` to Phase 12b; `SyncCommandBus` constructor locked to 4 args (`BusRegistry`, `HandlerAttributeIndex`, `MiddlewarePipeline`, `Profile` + clock for envelope creation).
- [ ] **H11** — Each Psalm rule (Phase 16, 7 rules) ships hook + AST node + Issue class + fixture pair.
- [ ] **H12** — OCC retry exhaustion observability + IdempotencyStore TTL contract (Phase 7 step 4 ships `ttl()`; Phase 10c step 3 ships metrics + WARN + `RetryBudgetExhaustedException`).
- [ ] **H13** — `BusBuilder::withMiddleware(Middleware, ?PipelineStage $before)` adopter extension (Phase 12a step 3); `CustomMiddlewareRegistration` + `BusBuildResult::$customMiddlewares` carry registrations to Phase 13's pipeline assembler.
- [ ] **CV1** — `Accepted::instance()` cached singleton DROPPED upstream (parallel agent); plan uses `new Accepted()`.
- [ ] **CV2** — `Principal` interface + `Option<Principal>` on validation/authorization contexts; the 2 narrow exceptions (PHP attribute defaults `Authorize::$subject: ?string` and `Authorize::$before: ?string`) documented.
- [ ] **CV3** — `#[\NoDiscard]` swept; visible in Violations, RoutingResolution, Composite, ExplicitOnly, NamespacePattern, ValidationContext, AuthorizationContext, IdempotencyReservation impl, etc.
- [ ] **CV4** — `clone($this, [...])` (PHP 8.5 clone-with) used for VO mutators throughout.
- [ ] **CV5** — Comments-write-less swept: no section dividers, no restating-the-code, no status-of-task in any code block.
- [ ] **M1** — `IdempotencyReservation` is now an interface (Phase 7 step 2); `InMemoryReservation` concrete (Phase 7 step 3).
- [ ] **M2** — `BusBootException` and `BusRuntimeException` intermediate abstract classes (Phase 2 step 4).
- [ ] **M3** — `#[CommandHandler]` attribute renamed to `#[Handler]` (Phase 8 step 7).
- [ ] **M4** — `bin/ddd` shell shim moved out (Phase 14 of v2 ships only `Cli\Command` + `RoutesShowCommand` service); deferred to `nexus-ddd-cli` (TBD).
- [ ] **M5** — `#[Sensitive]` attribute (Phase 8 step 8) + `PayloadRedactor` (Phase 10a step 4) + `LoggingMiddleware` default-deny payload-at-DEBUG (Phase 10a step 5).
- [ ] **M6** — `MetricOutcome` enum (Phase 6 step 1); used by all metric `count` calls.
- [ ] **M7** — Causation chain through emitted events (Phase 10c step 6 + Phase 15 step 11 smoke test).
- [ ] **M8** — `NEXUS_BUS_RETRY_BUDGET_MS_SYNC` env var support documented in Phase 10c step 3 + README ops section.
- [ ] **L1** — `PipelineContext` uses `public private(set)` asymmetric visibility (Phase 9 step 4).
- [ ] **L2** — `RoutingResolution::$resolvedBy: class-string<RoutingStrategy>` typed (Phase 11 step 2); `displayName(): string`.
- [ ] **L3** — Phase 14 of v1 (IdempotencyKeyResolver integration) folded into Phase 10c (resolver + middleware + integration in one).
- [ ] **L4** — Phase 19 step 2 enforces 90% method-coverage gate.
- [ ] **L5** — `SmokeBenchmarkTest` ships in Phase 15 step 13 (10000 dispatches < 50ms).
- [ ] **L6** — `IdempotencyHttpHeaderBridgeSmokeTest` in Phase 15 step 10.
- [ ] **L7** — Sentinel string constants for `Authorize::$subject` documented as option; attribute keeps `?string` per attribute-default rule.
- [ ] **L8** — Co-Authored-By reminder in Phase 0 conventions.
- [ ] **L9** — Risk Register section after file structure.

**Out-of-scope items clearly marked deferred:**
- `AsyncCommandBus` / `AsyncEventBus` → `nexus-ddd-async` (P3)
- `ActorCommandBus` → `nexus-ddd-actor` (P4)
- `OutboxEventBus` (DB-backed outbox + relay) → `nexus-ddd-outbox` (P3)
- DB-backed `IdempotencyStore` → `nexus-ddd-bus-idempotency-doctrine`
- Symfony bundle → `nexus-ddd-symfony` (P4)
- OpenTelemetry SDK adapter → `nexus-ddd-otel-adapter`
- `bin/ddd` shell shim → `nexus-ddd-cli` (TBD)

Other invariants:
- [ ] No `add()` method on the bus interface.
- [ ] `#[Handler]` attribute is created in this package (renamed from CommandHandler).
- [ ] `MessageId` / `MessageMetadata` / `Headers` / `Accepted` reused from messaging.
- [ ] `EnvelopedCommandBus` / `EnvelopedQueryBus` / `EnvelopedEventBus` reused from messaging.
- [ ] No singleton classes in implementation. `Accepted` constructed via `new Accepted()`.
- [ ] Phase 16 ships exactly 7 Psalm rules.
- [ ] Phase 17 ships 3 fitness tests.
- [ ] Phase 18 README documents deferral categories.
- [ ] Phase 19 PR title fits under 70 characters.
- [ ] No `MultiAggregateTransactionException` enforcement (lives in aggregate package).
- [ ] All template bounds reconciled.
- [ ] No `?T` in framework signatures except the documented exceptions (`Authorize::$subject`, `Authorize::$before`, `AccessDeniedException::for(?Principal)`).
- [ ] Pre-commit GrumPHP runs in Docker.

---

## Execution handoff

After this plan is reviewed:

**1. Subagent-Driven (recommended)**

```
/superpowers:subagent-driven-development docs/superpowers/plans/2026-05-09-nexus-ddd-bus-plan.md
```

**2. Inline execution**

```
/superpowers:executing-plans docs/superpowers/plans/2026-05-09-nexus-ddd-bus-plan.md
```

The plan has been validated against:
- The aggregate plan's structure (`docs/superpowers/plans/2026-05-08-nexus-ddd-aggregate-plan.md`).
- The umbrella spec v7.
- The shipped messaging-package code (post-parallel-agent: `Headers` on `MessageMetadata`, `Accepted` marker, `tryDispatch` on `CommandBus`, `MessageInbox::markCompleted`).
- The shipped Psalm plugin structure.
- The shipped deptrac layer structure.

For follow-up packages, refer to the umbrella spec v7 §11 + §12 + §16 + §26 + this plan's "Out of scope" list.
