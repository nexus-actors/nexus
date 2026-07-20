# Nexus Remediation Backlog

> Generated from the canonical independent audit by `bin/build-audit-deliverables.mjs`. Edit planning metadata in the generator and regenerate; do not hand-edit generated task bodies.

**Scope:** 68 findings (37 High, 31 Medium, 0 Low)
**Source:** [Nexus Independent Product and Engineering Audit](./2026-07-16-nexus-independent-audit.md#consolidated-findings)

## Planning Model

| Effort | Working estimate |
|---|---|
| S | <=3 days |
| M | 4-10 days |
| L | 2-5 weeks |
| XL | 1-3 months; likely RFC and staged rollout |

Effort is a planning band, not a commitment. Each task must be refined against the release candidate, assigned an accountable owner, and split into implementation issues when its acceptance criteria cannot fit one pull request.

## Release Policy

Every High finding must be closed, its affected feature or public claim must be removed, or a named accountable owner must approve a time-bounded risk acceptance with explicit deployment controls. Labeling the project or feature "experimental" is not sufficient risk acceptance.

## Closure Policy

A technical finding closes only when its implementation, required tests, and documentation or compatibility changes land together, or when the affected feature is deliberately removed. Documentation-only wording changes do not close technical findings. Documentation tasks validate the corrected implementation and its executable examples; they do not substitute for remediation.

## Program Sizing

A mechanical standalone sum of the task bands is approximately **36-99 engineer-months**. That sum assumes every issue is delivered independently, so it overstates work where one shared runtime, persistence, security, or release implementation closes several findings.

The preliminary consolidated portfolio hypothesis is **24-48 engineer-months** after accounting for shared implementations and coordinated delivery. This range must be refined through the Phase 1-4 RFCs, dependency discovery, staffing model, and staged rollout plans. Effort bands are not additive commitments. This generated backlog is the authoritative sizing model and supersedes earlier rough estimates.

## Task Index

| Phase | Tasks |
|---|---:|
| Phase 1 - Core correctness and security | 21 |
| Phase 2 - Lifecycle and delivery | 10 |
| Phase 3 - Durable DDD and persistence | 9 |
| Phase 4 - Scaling and operations | 7 |
| Phase 5 - Documentation, examples, packaging, and release assurance | 21 |

## Technical Domain Index

| Technical domain | Findings | Task links |
|---|---:|---|
| Domain model and persistence | 5 | [DDD-001](#ddd-001), [DDD-002](#ddd-002), [DDD-003](#ddd-003), [DDD-004](#ddd-004), [DDD-005](#ddd-005) |
| Public API and DSL | 10 | [DSL-001](#dsl-001), [DSL-002](#dsl-002), [DSL-003](#dsl-003), [DSL-004](#dsl-004), [DSL-005](#dsl-005), [DSL-006](#dsl-006), [DSL-007](#dsl-007), [DSL-008](#dsl-008), [DSL-009](#dsl-009), [DSL-010](#dsl-010) |
| Architecture and packaging | 3 | [ARCH-001](#arch-001), [ARCH-002](#arch-002), [ARCH-003](#arch-003) |
| Runtime reliability | 10 | [REL-001](#rel-001), [REL-002](#rel-002), [REL-003](#rel-003), [REL-004](#rel-004), [REL-005](#rel-005), [REL-006](#rel-006), [REL-007](#rel-007), [REL-008](#rel-008), [REL-009](#rel-009), [REL-010](#rel-010) |
| Scaling and transport | 4 | [SCALE-001](#scale-001), [SCALE-002](#scale-002), [SCALE-003](#scale-003), [SCALE-004](#scale-004) |
| Operations and observability | 5 | [OPS-001](#ops-001), [OPS-002](#ops-002), [OPS-003](#ops-003), [OPS-004](#ops-004), [OPS-005](#ops-005) |
| Security | 15 | [SEC-001](#sec-001), [SEC-002](#sec-002), [SEC-003](#sec-003), [SEC-004](#sec-004), [SEC-005](#sec-005), [SEC-006](#sec-006), [SEC-007](#sec-007), [SEC-008](#sec-008), [SEC-009](#sec-009), [SEC-010](#sec-010), [SEC-011](#sec-011), [SEC-012](#sec-012), [SEC-013](#sec-013), [SEC-014](#sec-014), [SEC-015](#sec-015) |
| Documentation and developer experience | 10 | [DOC-001](#doc-001), [DOC-002](#doc-002), [DOC-003](#doc-003), [DOC-004](#doc-004), [DOC-005](#doc-005), [DOC-006](#doc-006), [DOC-007](#doc-007), [DOC-008](#doc-008), [DOC-009](#doc-009), [DOC-010](#doc-010) |
| Quality and release engineering | 6 | [QA-001](#qa-001), [QA-002](#qa-002), [QA-003](#qa-003), [QA-004](#qa-004), [QA-005](#qa-005), [QA-006](#qa-006) |

## Phase 1 - Core correctness and security

<a id="ddd-001"></a>

### DDD-001: Interpret every persistence effect hook

- [ ] Task status
- **Severity:** High
- **Effort:** L
- **Phase:** Phase 1 - Core correctness and security
- **Technical domain:** Domain model and persistence
- **Dependencies:** None

**Problem**

`Effect::thenReply()`/`thenRun()` store hooks, but `PersistenceEngine::create()` returns unchanged state for `None`; hooks run only through `handlePersist()` (`packages/nexus-persistence/src/EventSourced/Effect.php:127-166`, `packages/nexus-persistence/src/EventSourced/PersistenceEngine.php`). Durable state has the same defect.

**Impact**

Documented read queries time out silently.

**Implementation scope**

- Centralize effect interpretation and test every base effect/hook combination.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Matrix-test None, Persist, Unhandled, reply, run, and stop hooks in event-sourced and durable-state interpreters. The concrete implementation contract is: Centralize effect interpretation and test every base effect/hook combination.

**Acceptance criteria**

- [ ] The required action is implemented: Centralize effect interpretation and test every base effect/hook combination.
- [ ] The domain regression evidence passes: Matrix-test None, Persist, Unhandled, reply, run, and stop hooks in event-sourced and durable-state interpreters.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Matrix-test None, Persist, Unhandled, reply, run, and stop hooks in event-sourced and durable-state interpreters.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Centralize effect interpretation and test every base effect/hook combination.
- Update persistence/DDD guarantees and add a migration note for stored streams or command protocols affected by the change.

**Source audit:** [DDD-001](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 244)

<a id="dsl-002"></a>

### DSL-002: Resolve the HTTP actor-handler contract

- [ ] Task status
- **Severity:** Medium
- **Effort:** M
- **Phase:** Phase 1 - Core correctness and security
- **Technical domain:** Public API and DSL
- **Dependencies:** None

**Problem**

HTTP docs advertise `'#orders'`, but `HandlerResolver` supports only closures, `Class::method`, or invokable classes (`packages/nexus-http/src/Handler/HandlerResolver.php:54-70`). The implemented actor path is `#[FromActor]`.

**Impact**

Copied shorthand examples fail with reflection errors.

**Implementation scope**

- Implement a defined actor HTTP adapter or remove the shorthand and document `#[FromActor]`.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Compile and invoke every documented handler form, including invalid actor shorthand and the supported FromActor path. The concrete implementation contract is: Implement a defined actor HTTP adapter or remove the shorthand and document `#[FromActor]`.

**Acceptance criteria**

- [ ] The required action is implemented: Implement a defined actor HTTP adapter or remove the shorthand and document `#[FromActor]`.
- [ ] The domain regression evidence passes: Compile and invoke every documented handler form, including invalid actor shorthand and the supported FromActor path.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Compile and invoke every documented handler form, including invalid actor shorthand and the supported FromActor path.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Implement a defined actor HTTP adapter or remove the shorthand and document `#[FromActor]`.
- Update API reference and runnable examples; document the compatibility behavior for callers using the old DSL contract.

**Source audit:** [DSL-002](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 250)

<a id="dsl-003"></a>

### DSL-003: Make HTTP compilation terminal and deterministic

- [ ] Task status
- **Severity:** Medium
- **Effort:** M
- **Phase:** Phase 1 - Core correctness and security
- **Technical domain:** Public API and DSL
- **Dependencies:** None

**Problem**

Repeated `HttpApp::compile()` rebuilds worker-local actor tables and respawns the same names (`packages/nexus-http/src/Dsl/HttpApp.php`, `packages/nexus-http/src/Actor/ResolvedActorTable.php`).

**Impact**

Second compilation throws `ActorNameExistsException`.

**Implementation scope**

- Make compilation terminal/idempotent and test every actor mode.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Compile twice in global and worker-local actor modes and verify either identity-safe idempotence or a deterministic terminal-state exception. The concrete implementation contract is: Make compilation terminal/idempotent and test every actor mode.

**Acceptance criteria**

- [ ] The required action is implemented: Make compilation terminal/idempotent and test every actor mode.
- [ ] The domain regression evidence passes: Compile twice in global and worker-local actor modes and verify either identity-safe idempotence or a deterministic terminal-state exception.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Compile twice in global and worker-local actor modes and verify either identity-safe idempotence or a deterministic terminal-state exception.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Make compilation terminal/idempotent and test every actor mode.
- Update API reference and runnable examples; document the compatibility behavior for callers using the old DSL contract.

**Source audit:** [DSL-003](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 251)

<a id="dsl-008"></a>

### DSL-008: Replace public contract assertions

- [ ] Task status
- **Severity:** Medium
- **Effort:** M
- **Phase:** Phase 1 - Core correctness and security
- **Technical domain:** Public API and DSL
- **Dependencies:** None

**Problem**

Public factory and reply contracts rely on `assert()` (`packages/nexus-core/src/Actor/Props.php:92,143,163`).

**Impact**

Production with assertions disabled can defer invalid values into obscure failures.

**Implementation scope**

- Throw explicit contract exceptions; test with `zend.assertions=-1`.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Run invalid Props and reply construction with zend.assertions=-1 and assert stable public exceptions at the call boundary. The concrete implementation contract is: Throw explicit contract exceptions; test with `zend.assertions=-1`.

**Acceptance criteria**

- [ ] The required action is implemented: Throw explicit contract exceptions; test with `zend.assertions=-1`.
- [ ] The domain regression evidence passes: Run invalid Props and reply construction with zend.assertions=-1 and assert stable public exceptions at the call boundary.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Run invalid Props and reply construction with zend.assertions=-1 and assert stable public exceptions at the call boundary.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Throw explicit contract exceptions; test with `zend.assertions=-1`.
- Update API reference and runnable examples; document the compatibility behavior for callers using the old DSL contract.

**Source audit:** [DSL-008](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 256)

<a id="dsl-009"></a>

### DSL-009: Type-check FromActor parameters at compile time

- [ ] Task status
- **Severity:** Medium
- **Effort:** S
- **Phase:** Phase 1 - Core correctness and security
- **Technical domain:** Public API and DSL
- **Dependencies:** None

**Problem**

`#[FromActor]` validates name/scope but not that the parameter accepts `ActorRef` (`packages/nexus-http/src/Handler/Resolver/Builtin/FromActorResolver.php`).

**Impact**

Bad handlers compile and fail during invocation.

**Implementation scope**

- Validate reflected parameter type at compile time.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Compile valid ActorRef parameters plus scalar, union, nullable, and incompatible object parameters; reject invalid handlers before serving. The concrete implementation contract is: Validate reflected parameter type at compile time.

**Acceptance criteria**

- [ ] The required action is implemented: Validate reflected parameter type at compile time.
- [ ] The domain regression evidence passes: Compile valid ActorRef parameters plus scalar, union, nullable, and incompatible object parameters; reject invalid handlers before serving.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Compile valid ActorRef parameters plus scalar, union, nullable, and incompatible object parameters; reject invalid handlers before serving.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Validate reflected parameter type at compile time.
- Update API reference and runnable examples; document the compatibility behavior for callers using the old DSL contract.

**Source audit:** [DSL-009](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 257)

<a id="rel-001"></a>

### REL-001: Make Swoole mailbox admission truthful

- [ ] Task status
- **Severity:** High
- **Effort:** L
- **Phase:** Phase 1 - Core correctness and security
- **Technical domain:** Runtime reliability
- **Dependencies:** None

**Problem**

Swoole "unbounded" is a 65,536-slot channel; failed `push()` is ignored and returned as `Accepted` (`packages/nexus-runtime-swoole/src/SwooleMailbox.php:37,74-97`).

**Impact**

Messages and asks can be silently lost under overload.

**Implementation scope**

- Expose the real capacity, inspect every push result/error, return dropped/backpressured, and fail asks immediately.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Saturate each Swoole mailbox strategy at and beyond capacity; assert accepted, dropped, backpressured, and failed asks match actual pushes. The concrete implementation contract is: Expose the real capacity, inspect every push result/error, return dropped/backpressured, and fail asks immediately.

**Acceptance criteria**

- [ ] The required action is implemented: Expose the real capacity, inspect every push result/error, return dropped/backpressured, and fail asks immediately.
- [ ] The domain regression evidence passes: Saturate each Swoole mailbox strategy at and beyond capacity; assert accepted, dropped, backpressured, and failed asks match actual pushes.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Saturate each Swoole mailbox strategy at and beyond capacity; assert accepted, dropped, backpressured, and failed asks match actual pushes.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Expose the real capacity, inspect every push result/error, return dropped/backpressured, and fail asks immediately.
- Publish the cross-runtime lifecycle or delivery contract and call out any changed failure outcome or default.

**Source audit:** [REL-001](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 267)

<a id="rel-005"></a>

### REL-005: Cancel the active Swoole repeating timer

- [ ] Task status
- **Severity:** High
- **Effort:** M
- **Phase:** Phase 1 - Core correctness and security
- **Technical domain:** Runtime reliability
- **Dependencies:** None

**Problem**

Repeating Swoole timers return a cancellable for the initial `after` ID, then create a different `tick` ID (`packages/nexus-runtime-swoole/src/SwooleRuntime.php:220-245`).

**Impact**

Cancellation after first fire fails; stopped/restarted actors can retain ticks.

**Implementation scope**

- Return a handle linked to the real tick and add post-first-fire cancellation tests without runtime shutdown.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Cancel before and after the first repeat tick, then stop/restart the owner and assert no later tick fires. The concrete implementation contract is: Return a handle linked to the real tick and add post-first-fire cancellation tests without runtime shutdown.

**Acceptance criteria**

- [ ] The required action is implemented: Return a handle linked to the real tick and add post-first-fire cancellation tests without runtime shutdown.
- [ ] The domain regression evidence passes: Cancel before and after the first repeat tick, then stop/restart the owner and assert no later tick fires.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Cancel before and after the first repeat tick, then stop/restart the owner and assert no later tick fires.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Return a handle linked to the real tick and add post-first-fire cancellation tests without runtime shutdown.
- Publish the cross-runtime lifecycle or delivery contract and call out any changed failure outcome or default.

**Source audit:** [REL-005](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 271)

<a id="sec-001"></a>

### SEC-001: Authorize WebSockets before upgrade

- [ ] Task status
- **Severity:** High
- **Effort:** XL
- **Phase:** Phase 1 - Core correctness and security
- **Technical domain:** Security
- **Dependencies:** None

**Problem**

Swoole WebSocket `Open` translates and dispatches directly, bypassing HTTP/auth middleware (`packages/nexus-http-server-swoole/src/Bridge/SwooleServerEventBinder.php:77-95`). WS compilation constructs its own handler registry without shared principal resolution (`packages/nexus-http-ws/src/WebSocket/HandlerInstantiator.php:123-140`, `packages/nexus-http-ws/src/WsApplication.php`).

**Impact**

WebSocket routes documented with auth can be reached without that pipeline; principal injection is not wired.

**Implementation scope**

- Authenticate/authorize in pre-upgrade handshake, add WS route middleware and resolver sharing, reject before 101, and add real Swoole token tests.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Real-Swoole tests for missing, invalid, expired, and scoped tokens; assert rejection before 101 and shared principal resolution after upgrade. The concrete implementation contract is: Authenticate/authorize in pre-upgrade handshake, add WS route middleware and resolver sharing, reject before 101, and add real Swoole token tests.

**Acceptance criteria**

- [ ] The required action is implemented: Authenticate/authorize in pre-upgrade handshake, add WS route middleware and resolver sharing, reject before 101, and add real Swoole token tests.
- [ ] The domain regression evidence passes: Real-Swoole tests for missing, invalid, expired, and scoped tokens; assert rejection before 101 and shared principal resolution after upgrade.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Real-Swoole tests for missing, invalid, expired, and scoped tokens; assert rejection before 101 and shared principal resolution after upgrade.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Authenticate/authorize in pre-upgrade handshake, add WS route middleware and resolver sharing, reject before 101, and add real Swoole token tests.
- Update the threat model and secure-configuration guide; provide an upgrade note for stricter defaults or rejected traffic.

**Source audit:** [SEC-001](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 302)

<a id="sec-003"></a>

### SEC-003: Make annotated HTTP authorization fail closed

- [ ] Task status
- **Severity:** High
- **Effort:** L
- **Phase:** Phase 1 - Core correctness and security
- **Technical domain:** Security
- **Dependencies:** None

**Problem**

`#[RequiresAuth]`/`#[RequiresScope]` are inspected only by `AuthorizationMiddleware`; compile does not enforce it and authentication allows anonymous (`packages/nexus-http-auth/src/Middleware/AuthorizationMiddleware.php`, `packages/nexus-http-auth/src/Middleware/AuthenticationMiddleware.php`, `packages/nexus-http/src/Dsl/HttpApp.php`).

**Impact**

A protected class serves HTTP 200 if route authz middleware is omitted.

**Implementation scope**

- Auto-install authorization after routing or fail application compilation for unprotected annotated routes.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Compile annotated routes with missing/misordered middleware and assert startup failure; test anonymous, wrong-scope, and valid-scope requests. The concrete implementation contract is: Auto-install authorization after routing or fail application compilation for unprotected annotated routes.

**Acceptance criteria**

- [ ] The required action is implemented: Auto-install authorization after routing or fail application compilation for unprotected annotated routes.
- [ ] The domain regression evidence passes: Compile annotated routes with missing/misordered middleware and assert startup failure; test anonymous, wrong-scope, and valid-scope requests.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Compile annotated routes with missing/misordered middleware and assert startup failure; test anonymous, wrong-scope, and valid-scope requests.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Auto-install authorization after routing or fail application compilation for unprotected annotated routes.
- Update the threat model and secure-configuration guide; provide an upgrade note for stricter defaults or rejected traffic.

**Source audit:** [SEC-003](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 304)

<a id="sec-004"></a>

### SEC-004: Replace default native object deserialization

- [ ] Task status
- **Severity:** Medium
- **Effort:** XL
- **Phase:** Phase 1 - Core correctness and security
- **Technical domain:** Security
- **Dependencies:** None

**Problem**

`PhpNativeSerializer` defaults to allow-all and invokes `unserialize()` before top-level checks; DB persistence adapters instantiate it by default (`packages/nexus-serialization/src/PhpNativeSerializer.php:20-45,68-100`, `packages/nexus-persistence-dbal/src/DbalEventStore.php:20-25`). Safer serializers and allowlists exist but are not the default. If an attacker or operator can influence persisted rows, gadget construction can become High-impact RCE.

**Impact**

Recovery crosses an unsafe native object-deserialization boundary.

**Implementation scope**

- Default to schema codecs and explicit type registries; make native deserialization an explicit trusted-data opt-in with nested allowlists.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Recover registered/unregistered and nested payload types; inject gadget-like/tampered rows and prove no constructor or magic method executes. The concrete implementation contract is: Default to schema codecs and explicit type registries; make native deserialization an explicit trusted-data opt-in with nested allowlists.

**Acceptance criteria**

- [ ] The required action is implemented: Default to schema codecs and explicit type registries; make native deserialization an explicit trusted-data opt-in with nested allowlists.
- [ ] The domain regression evidence passes: Recover registered/unregistered and nested payload types; inject gadget-like/tampered rows and prove no constructor or magic method executes.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Recover registered/unregistered and nested payload types; inject gadget-like/tampered rows and prove no constructor or magic method executes.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Default to schema codecs and explicit type registries; make native deserialization an explicit trusted-data opt-in with nested allowlists.
- Update the threat model and secure-configuration guide; provide an upgrade note for stricter defaults or rejected traffic.

**Source audit:** [SEC-004](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 305)

<a id="sec-005"></a>

### SEC-005: Sanitize pooled database connections

- [ ] Task status
- **Severity:** Medium
- **Effort:** L
- **Phase:** Phase 1 - Core correctness and security
- **Technical domain:** Security
- **Dependencies:** None

**Problem**

DB connection release requeues without rollback or session reset (`packages/nexus-doctrine-dbal/src/Pool/ConnectionPool.php:91-131`); ORM reuses the underlying connection. Multi-tenant or session-role deployments make the impact High.

**Impact**

A later request can inherit an active transaction, role, search path, locks, or tenant state.

**Implementation scope**

- Roll back while active, reset session state or evict, poison on cleanup failure, and partition tenant pools.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Sequential tenant tests cover open transactions, roles, search_path/session settings, locks, cleanup failure, and poisoned-connection eviction. The concrete implementation contract is: Roll back while active, reset session state or evict, poison on cleanup failure, and partition tenant pools.

**Acceptance criteria**

- [ ] The required action is implemented: Roll back while active, reset session state or evict, poison on cleanup failure, and partition tenant pools.
- [ ] The domain regression evidence passes: Sequential tenant tests cover open transactions, roles, search_path/session settings, locks, cleanup failure, and poisoned-connection eviction.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Sequential tenant tests cover open transactions, roles, search_path/session settings, locks, cleanup failure, and poisoned-connection eviction.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Roll back while active, reset session state or evict, poison on cleanup failure, and partition tenant pools.
- Update the threat model and secure-configuration guide; provide an upgrade note for stricter defaults or rejected traffic.

**Source audit:** [SEC-005](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 306)

<a id="sec-006"></a>

### SEC-006: Enforce JWT issuer and audience constraints

- [ ] Task status
- **Severity:** High
- **Effort:** M
- **Phase:** Phase 1 - Core correctness and security
- **Technical domain:** Security
- **Dependencies:** None

**Problem**

JWT validation asserts signature and time but ignores configured issuer/audience constraints (`packages/nexus-http-auth/src/Authenticator/JwtAuthenticator.php:61-78,103-108`). Shared signing keys make cross-issuer/service replay directly exploitable.

**Impact**

Correctly signed tokens for another issuer/service/tenant can be replayed.

**Implementation scope**

- Merge configured constraints and require/test issuer, audience, subject, and clock skew.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Reject validly signed tokens with wrong/missing issuer, audience, subject, or time skew; accept only configured claims. The concrete implementation contract is: Merge configured constraints and require/test issuer, audience, subject, and clock skew.

**Acceptance criteria**

- [ ] The required action is implemented: Merge configured constraints and require/test issuer, audience, subject, and clock skew.
- [ ] The domain regression evidence passes: Reject validly signed tokens with wrong/missing issuer, audience, subject, or time skew; accept only configured claims.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Reject validly signed tokens with wrong/missing issuer, audience, subject, or time skew; accept only configured claims.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Merge configured constraints and require/test issuer, audience, subject, and clock skew.
- Update the threat model and secure-configuration guide; provide an upgrade note for stricter defaults or rejected traffic.

**Source audit:** [SEC-006](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 307)

<a id="sec-007"></a>

### SEC-007: Secure cluster production defaults

- [ ] Task status
- **Severity:** High
- **Effort:** L
- **Phase:** Phase 1 - Core correctness and security
- **Technical domain:** Security
- **Dependencies:** None

**Problem**

Cluster topology defaults TLS and authentication to null (`packages/nexus-cluster-tcp/src/ClusterTopology.php:76-158`). The exposure requires a reachable insecure production deployment, but that is an allowed default configuration.

**Impact**

Any reachable peer can join and send control/actor traffic when production config omits security.

**Implementation scope**

- Add secure production factory; reject non-loopback insecure bind unless explicit development override.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Reject non-loopback insecure production binds; handshake tests cover trusted/untrusted certificates, HMAC, downgrade attempts, and explicit dev override. The concrete implementation contract is: Add secure production factory; reject non-loopback insecure bind unless explicit development override.

**Acceptance criteria**

- [ ] The required action is implemented: Add secure production factory; reject non-loopback insecure bind unless explicit development override.
- [ ] The domain regression evidence passes: Reject non-loopback insecure production binds; handshake tests cover trusted/untrusted certificates, HMAC, downgrade attempts, and explicit dev override.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Reject non-loopback insecure production binds; handshake tests cover trusted/untrusted certificates, HMAC, downgrade attempts, and explicit dev override.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Add secure production factory; reject non-loopback insecure bind unless explicit development override.
- Update the threat model and secure-configuration guide; provide an upgrade note for stricter defaults or rejected traffic.

**Source audit:** [SEC-007](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 308)

<a id="sec-009"></a>

### SEC-009: Enforce request limits before allocation

- [ ] Task status
- **Severity:** Medium
- **Effort:** L
- **Phase:** Phase 1 - Core correctness and security
- **Technical domain:** Security
- **Dependencies:** None

**Problem**

Swoole materializes `rawContent()` before PSR body middleware; body limit skips unknown lengths and GET bodies (`packages/nexus-http-server-swoole/src/Bridge/SwooleRequestTranslator.php:31-56`, `packages/nexus-http-toolkit/src/Middleware/BodySizeLimitMiddleware.php:61-100`).

**Impact**

Request-memory exhaustion occurs before framework limit enforcement.

**Implementation scope**

- Configure native Swoole size/header/time limits, count streams, check all methods, and add edge concurrency/rate limits.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Real-Swoole concurrency tests cover oversized known/unknown/chunked bodies, GET bodies, slow clients, headers, and native rejection limits. The concrete implementation contract is: Configure native Swoole size/header/time limits, count streams, check all methods, and add edge concurrency/rate limits.

**Acceptance criteria**

- [ ] The required action is implemented: Configure native Swoole size/header/time limits, count streams, check all methods, and add edge concurrency/rate limits.
- [ ] The domain regression evidence passes: Real-Swoole concurrency tests cover oversized known/unknown/chunked bodies, GET bodies, slow clients, headers, and native rejection limits.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Real-Swoole concurrency tests cover oversized known/unknown/chunked bodies, GET bodies, slow clients, headers, and native rejection limits.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Configure native Swoole size/header/time limits, count streams, check all methods, and add edge concurrency/rate limits.
- Update the threat model and secure-configuration guide; provide an upgrade note for stricter defaults or rejected traffic.

**Source audit:** [SEC-009](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 310)

<a id="sec-011"></a>

### SEC-011: Separate public liveness from private readiness

- [ ] Task status
- **Severity:** Medium
- **Effort:** M
- **Phase:** Phase 1 - Core correctness and security
- **Technical domain:** Security
- **Dependencies:** None

**Problem**

Health handler returns check details plus exception class/message; docs show a public `/health` route (`packages/nexus-http-toolkit/src/Health/HealthCheckHandler.php:49-59`).

**Impact**

Internal topology, component, or error details can be disclosed.

**Implementation scope**

- Separate opaque liveness from authenticated/internal readiness and redact log/health errors.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Fail dependencies with sensitive exception messages and verify public liveness stays opaque while authenticated readiness and logs retain controlled detail. The concrete implementation contract is: Separate opaque liveness from authenticated/internal readiness and redact log/health errors.

**Acceptance criteria**

- [ ] The required action is implemented: Separate opaque liveness from authenticated/internal readiness and redact log/health errors.
- [ ] The domain regression evidence passes: Fail dependencies with sensitive exception messages and verify public liveness stays opaque while authenticated readiness and logs retain controlled detail.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Fail dependencies with sensitive exception messages and verify public liveness stays opaque while authenticated readiness and logs retain controlled detail.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Separate opaque liveness from authenticated/internal readiness and redact log/health errors.
- Update the threat model and secure-configuration guide; provide an upgrade note for stricter defaults or rejected traffic.

**Source audit:** [SEC-011](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 312)

<a id="dsl-001"></a>

### DSL-001: Route persistent unhandled commands correctly

- [ ] Task status
- **Severity:** High
- **Effort:** M
- **Phase:** Phase 1 - Core correctness and security
- **Technical domain:** Public API and DSL
- **Dependencies:** [DDD-001](#ddd-001)

**Problem**

Persistent `Effect::unhandled()` maps to unchanged state, despite promising dead-letter routing (`packages/nexus-persistence/src/EventSourced/Effect.php`, `packages/nexus-persistence/src/EventSourced/PersistenceEngine.php`; durable-state equivalent).

**Impact**

Protocol drift is silently swallowed.

**Implementation scope**

- Provide an unhandled transition that reaches actor dead letters or an explicit context API.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Send unsupported commands through event-sourced and durable-state behaviors and assert the documented dead-letter or explicit unhandled outcome. The concrete implementation contract is: Provide an unhandled transition that reaches actor dead letters or an explicit context API.

**Acceptance criteria**

- [ ] The required action is implemented: Provide an unhandled transition that reaches actor dead letters or an explicit context API.
- [ ] The domain regression evidence passes: Send unsupported commands through event-sourced and durable-state behaviors and assert the documented dead-letter or explicit unhandled outcome.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Send unsupported commands through event-sourced and durable-state behaviors and assert the documented dead-letter or explicit unhandled outcome.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Provide an unhandled transition that reaches actor dead letters or an explicit context API.
- Update API reference and runnable examples; document the compatibility behavior for callers using the old DSL contract.

**Source audit:** [DSL-001](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 249)

<a id="dsl-004"></a>

### DSL-004: Standardize persistence handler signatures

- [ ] Task status
- **Severity:** High
- **Effort:** L
- **Phase:** Phase 1 - Core correctness and security
- **Technical domain:** Public API and DSL
- **Dependencies:** [DDD-001](#ddd-001)

**Problem**

Persistence docs show `(context, command, state)`, but the engine invokes `(state, context, command)` (`packages/nexus-persistence/src/EventSourced/PersistenceEngine.php`; `website/docs/persistence/event-sourcing.md:30-38`).

**Impact**

Flagship examples fail on first command and similar DSLs require different mental models.

**Implementation scope**

- Standardize signatures and execute documentation examples in CI.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Execute documented event-sourced, durable-state, and Doctrine handler examples with reflection/static-analysis checks for one argument order. The concrete implementation contract is: Standardize signatures and execute documentation examples in CI.

**Acceptance criteria**

- [ ] The required action is implemented: Standardize signatures and execute documentation examples in CI.
- [ ] The domain regression evidence passes: Execute documented event-sourced, durable-state, and Doctrine handler examples with reflection/static-analysis checks for one argument order.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Execute documented event-sourced, durable-state, and Doctrine handler examples with reflection/static-analysis checks for one argument order.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Standardize signatures and execute documentation examples in CI.
- Update API reference and runnable examples; document the compatibility behavior for callers using the old DSL contract.

**Source audit:** [DSL-004](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 252)

<a id="dsl-006"></a>

### DSL-006: Reject or apply routes added after compile

- [ ] Task status
- **Severity:** Medium
- **Effort:** S
- **Phase:** Phase 1 - Core correctness and security
- **Technical domain:** Public API and DSL
- **Dependencies:** [DSL-003](#dsl-003)

**Problem**

Routes registered after first compile remain in `pendingBuilders` because promotion occurs only on first compile (`packages/nexus-http/src/Dsl/HttpApp.php`, symbols `compile` and route promotion).

**Impact**

Configuration is silently ignored.

**Implementation scope**

- Reject all mutation after compile or promote on each compile.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Register routes before and after compile and assert no route can be silently ignored. The concrete implementation contract is: Reject all mutation after compile or promote on each compile.

**Acceptance criteria**

- [ ] The required action is implemented: Reject all mutation after compile or promote on each compile.
- [ ] The domain regression evidence passes: Register routes before and after compile and assert no route can be silently ignored.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Register routes before and after compile and assert no route can be silently ignored.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Reject all mutation after compile or promote on each compile.
- Update API reference and runnable examples; document the compatibility behavior for callers using the old DSL contract.

**Source audit:** [DSL-006](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 254)

<a id="sec-002"></a>

### SEC-002: Bound and passivate WebSocket channel actors

- [ ] Task status
- **Severity:** High
- **Effort:** L
- **Phase:** Phase 1 - Core correctness and security
- **Technical domain:** Security
- **Dependencies:** [SEC-001](#sec-001)

**Problem**

Arbitrary channel keys create actors; registry entries are only pruned on future lookup of the same key, `remove()` is unused, base actors stay alive on close, and mailboxes default unbounded (`packages/nexus-http-ws/src/WebSocket/WebSocketDispatcher.php`, `packages/nexus-http-ws/src/WebSocket/ChannelActorRegistry.php`, `packages/nexus-http-ws/src/WebSocket/WebSocketChannelActor.php`). The High rating applies to public non-passivating routes; strict authentication, cardinality limits, and correct application stop behavior reduce exposure.

**Impact**

Repeated unique paths exhaust actors, refs, mailboxes, and memory; SEC-001 removes the expected auth barrier.

**Implementation scope**

- Stop on last close by default, evict on lifecycle signal, cap/TTL/LRU channel cardinality, bound mailboxes, validate keys, and rate-limit.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Churn unique authenticated/unauthenticated channel keys; assert caps, TTL/LRU eviction, last-close stop, bounded mailboxes, and stable memory. The concrete implementation contract is: Stop on last close by default, evict on lifecycle signal, cap/TTL/LRU channel cardinality, bound mailboxes, validate keys, and rate-limit.

**Acceptance criteria**

- [ ] The required action is implemented: Stop on last close by default, evict on lifecycle signal, cap/TTL/LRU channel cardinality, bound mailboxes, validate keys, and rate-limit.
- [ ] The domain regression evidence passes: Churn unique authenticated/unauthenticated channel keys; assert caps, TTL/LRU eviction, last-close stop, bounded mailboxes, and stable memory.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Churn unique authenticated/unauthenticated channel keys; assert caps, TTL/LRU eviction, last-close stop, bounded mailboxes, and stable memory.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Stop on last close by default, evict on lifecycle signal, cap/TTL/LRU channel cardinality, bound mailboxes, validate keys, and rate-limit.
- Update the threat model and secure-configuration guide; provide an upgrade note for stricter defaults or rejected traffic.

**Source audit:** [SEC-002](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 303)

<a id="sec-010"></a>

### SEC-010: Prevent cross-site WebSocket and cookie attacks

- [ ] Task status
- **Severity:** Medium
- **Effort:** L
- **Phase:** Phase 1 - Core correctness and security
- **Technical domain:** Security
- **Dependencies:** [SEC-001](#sec-001)

**Problem**

The WebSocket open/dispatch path does not validate Origin, and `CookieTokenExtractor` only reads a cookie value (`packages/nexus-http-server-swoole/src/Bridge/SwooleServerEventBinder.php`, `packages/nexus-http-ws/src/WebSocket/WebSocketDispatcher.php`, `packages/nexus-http-auth/src/Extractor/CookieTokenExtractor.php`). Exploitation requires browser-carried credentials such as cookie bearer tokens.

**Impact**

Cross-site WebSocket hijacking and HTTP CSRF are possible with cookie bearer tokens.

**Implementation scope**

- Exact Origin allowlist, SameSite cookies, CSRF token, and rejection tests.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Browser-level handshake tests cover exact allowed/disallowed/null Origins, cookie credentials, CSRF tokens, and SameSite behavior. The concrete implementation contract is: Exact Origin allowlist, SameSite cookies, CSRF token, and rejection tests.

**Acceptance criteria**

- [ ] The required action is implemented: Exact Origin allowlist, SameSite cookies, CSRF token, and rejection tests.
- [ ] The domain regression evidence passes: Browser-level handshake tests cover exact allowed/disallowed/null Origins, cookie credentials, CSRF tokens, and SameSite behavior.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Browser-level handshake tests cover exact allowed/disallowed/null Origins, cookie credentials, CSRF tokens, and SameSite behavior.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Exact Origin allowlist, SameSite cookies, CSRF token, and rejection tests.
- Update the threat model and secure-configuration guide; provide an upgrade note for stricter defaults or rejected traffic.

**Source audit:** [SEC-010](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 311)

<a id="sec-013"></a>

### SEC-013: Harden wallet example administration

- [ ] Task status
- **Severity:** Medium
- **Effort:** M
- **Phase:** Phase 1 - Core correctness and security
- **Technical domain:** Security
- **Dependencies:** [SEC-003](#sec-003)

**Problem**

Wallet demo exposes `/admin/wallets` without auth and publishes demo DB/token defaults (`examples/nexus-wallet-app/src/Http/WalletRoutes.php:84-91`, `examples/nexus-wallet-app/README.md:97-100`, `examples/nexus-wallet-app/compose.yaml:17-40,74-83`).

**Impact**

Copied deployment leaks every owner/balance and exposes demo infrastructure.

**Implementation scope**

- Add admin role/authz, loopback-bind or unpublish DB, and fail production mode on demo defaults.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Exercise admin endpoints without/with wrong/valid roles and verify production mode rejects demo secrets and publicly bound database ports. The concrete implementation contract is: Add admin role/authz, loopback-bind or unpublish DB, and fail production mode on demo defaults.

**Acceptance criteria**

- [ ] The required action is implemented: Add admin role/authz, loopback-bind or unpublish DB, and fail production mode on demo defaults.
- [ ] The domain regression evidence passes: Exercise admin endpoints without/with wrong/valid roles and verify production mode rejects demo secrets and publicly bound database ports.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Exercise admin endpoints without/with wrong/valid roles and verify production mode rejects demo secrets and publicly bound database ports.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Add admin role/authz, loopback-bind or unpublish DB, and fail production mode on demo defaults.
- Update the threat model and secure-configuration guide; provide an upgrade note for stricter defaults or rejected traffic.

**Source audit:** [SEC-013](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 314)

## Phase 2 - Lifecycle and delivery

<a id="dsl-007"></a>

### DSL-007: Return typed application root handles

- [ ] Task status
- **Severity:** Medium
- **Effort:** L
- **Phase:** Phase 2 - Lifecycle and delivery
- **Technical domain:** Public API and DSL
- **Dependencies:** None

**Problem**

`NexusApp` spawns actors but discards refs; `onStart` gets only `ActorSystem`, with no public named lookup (`packages/nexus-app/src/NexusApp.php:156`).

**Impact**

The app DSL cannot compose typed root ports, so real applications bypass it.

**Implementation scope**

- Return a typed started-app registry and dependency-aware handles.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Start an app with multiple roots and verify callers can retrieve typed handles, dependency ordering, and shutdown ownership. The concrete implementation contract is: Return a typed started-app registry and dependency-aware handles.

**Acceptance criteria**

- [ ] The required action is implemented: Return a typed started-app registry and dependency-aware handles.
- [ ] The domain regression evidence passes: Start an app with multiple roots and verify callers can retrieve typed handles, dependency ordering, and shutdown ownership.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Start an app with multiple roots and verify callers can retrieve typed handles, dependency ordering, and shutdown ownership.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Return a typed started-app registry and dependency-aware handles.
- Update API reference and runnable examples; document the compatibility behavior for callers using the old DSL contract.

**Source audit:** [DSL-007](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 255)

<a id="ops-001"></a>

### OPS-001: Bound and unify dead-letter handling

- [ ] Task status
- **Severity:** High
- **Effort:** L
- **Phase:** Phase 2 - Lifecycle and delivery
- **Technical domain:** Operations and observability
- **Dependencies:** [REL-001](#rel-001)

**Problem**

`DeadLetterRef` retains every object forever, while some closed-mailbox paths bypass dead letters (`packages/nexus-core/src/Actor/DeadLetterRef.php`, `packages/nexus-core/src/Actor/LocalActorRef.php`).

**Impact**

Memory grows and delivery failures are inconsistently observable.

**Implementation scope**

- Bound retained samples, keep counters, emit events, and route all failure paths consistently.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Drive closed, missing, full, and stopped destinations; assert bounded samples, monotonic counters, emitted events, and stable memory. The concrete implementation contract is: Bound retained samples, keep counters, emit events, and route all failure paths consistently.

**Acceptance criteria**

- [ ] The required action is implemented: Bound retained samples, keep counters, emit events, and route all failure paths consistently.
- [ ] The domain regression evidence passes: Drive closed, missing, full, and stopped destinations; assert bounded samples, monotonic counters, emitted events, and stable memory.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Drive closed, missing, full, and stopped destinations; assert bounded samples, monotonic counters, emitted events, and stable memory.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Bound retained samples, keep counters, emit events, and route all failure paths consistently.
- Update operator runbooks, metrics/alerts, rollout and rollback procedures, and any changed retention/shutdown semantics.

**Source audit:** [OPS-001](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 280)

<a id="rel-002"></a>

### REL-002: Complete death watch and child cleanup

- [ ] Task status
- **Severity:** High
- **Effort:** XL
- **Phase:** Phase 2 - Lifecycle and delivery
- **Technical domain:** Runtime reliability
- **Dependencies:** None

**Problem**

Watchers are recorded but stop never sends `Terminated`; child maps are never pruned and names cannot be reused (`packages/nexus-core/src/Actor/ActorCell.php:214-249,356-361,681-684`).

**Impact**

Death watch, passivation, child lookup, and respawn contracts are broken.

**Implementation scope**

- Implement termination propagation, parent deregistration, and nested lifecycle tests.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Exercise nested normal/failure stops, Terminated delivery, parent deregistration, lookup removal, and actor-name reuse across runtimes. The concrete implementation contract is: Implement termination propagation, parent deregistration, and nested lifecycle tests.

**Acceptance criteria**

- [ ] The required action is implemented: Implement termination propagation, parent deregistration, and nested lifecycle tests.
- [ ] The domain regression evidence passes: Exercise nested normal/failure stops, Terminated delivery, parent deregistration, lookup removal, and actor-name reuse across runtimes.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Exercise nested normal/failure stops, Terminated delivery, parent deregistration, lookup removal, and actor-name reuse across runtimes.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Implement termination propagation, parent deregistration, and nested lifecycle tests.
- Publish the cross-runtime lifecycle or delivery contract and call out any changed failure outcome or default.

**Source audit:** [REL-002](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 268)

<a id="rel-008"></a>

### REL-008: Bound responder-side pending asks

- [ ] Task status
- **Severity:** High
- **Effort:** M
- **Phase:** Phase 2 - Lifecycle and delivery
- **Technical domain:** Runtime reliability
- **Dependencies:** None

**Problem**

Responder-side pending asks are stored for up to 30 seconds with no bound (`packages/nexus-messenger/src/Consumer/ReceiverActor.php`).

**Impact**

Producers can exhaust consumer memory.

**Implementation scope**

- Add a configurable cap, load shedding, timeouts, and metrics.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Flood pending asks above the configured limit and assert deterministic shedding, cleanup on timeout/disconnect, and accurate gauges. The concrete implementation contract is: Add a configurable cap, load shedding, timeouts, and metrics.

**Acceptance criteria**

- [ ] The required action is implemented: Add a configurable cap, load shedding, timeouts, and metrics.
- [ ] The domain regression evidence passes: Flood pending asks above the configured limit and assert deterministic shedding, cleanup on timeout/disconnect, and accurate gauges.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Flood pending asks above the configured limit and assert deterministic shedding, cleanup on timeout/disconnect, and accurate gauges.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Add a configurable cap, load shedding, timeouts, and metrics.
- Publish the cross-runtime lifecycle or delivery contract and call out any changed failure outcome or default.

**Source audit:** [REL-008](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 274)

<a id="rel-009"></a>

### REL-009: Expose TCP delivery admission outcomes

- [ ] Task status
- **Severity:** High
- **Effort:** XL
- **Phase:** Phase 2 - Lifecycle and delivery
- **Technical domain:** Runtime reliability
- **Dependencies:** None

**Problem**

TCP disconnected buffers silently drop beyond 100; no route and short writes can disappear while sent metrics increment (`packages/nexus-cluster-tcp/src/PeerConnection.php`, `packages/nexus-cluster-tcp/src/ClusterNode.php`, `packages/nexus-cluster-tcp/src/Swoole/SwoolePeerLink.php`).

**Impact**

Delivery telemetry can claim success for lost messages.

**Implementation scope**

- Return admitted/buffered/dropped outcomes; document at-most-once; add acknowledgement/retry/dedup if stronger semantics are claimed.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Inject disconnects, full buffers, partial writes, no-route, reconnect, and peer loss; correlate outcomes with sent/buffered/dropped metrics. The concrete implementation contract is: Return admitted/buffered/dropped outcomes; document at-most-once; add acknowledgement/retry/dedup if stronger semantics are claimed.

**Acceptance criteria**

- [ ] The required action is implemented: Return admitted/buffered/dropped outcomes; document at-most-once; add acknowledgement/retry/dedup if stronger semantics are claimed.
- [ ] The domain regression evidence passes: Inject disconnects, full buffers, partial writes, no-route, reconnect, and peer loss; correlate outcomes with sent/buffered/dropped metrics.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Inject disconnects, full buffers, partial writes, no-route, reconnect, and peer loss; correlate outcomes with sent/buffered/dropped metrics.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Return admitted/buffered/dropped outcomes; document at-most-once; add acknowledgement/retry/dedup if stronger semantics are claimed.
- Publish the cross-runtime lifecycle or delivery contract and call out any changed failure outcome or default.

**Source audit:** [REL-009](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 275)

<a id="sec-008"></a>

### SEC-008: Authorize cluster node identities and control

- [ ] Task status
- **Severity:** Medium
- **Effort:** XL
- **Phase:** Phase 2 - Lifecycle and delivery
- **Technical domain:** Security
- **Dependencies:** [SEC-007](#sec-007)

**Problem**

An admitted cluster member can steer endpoints/control events and address exposed actors; shared HMAC proves group secret possession, not node identity (`packages/nexus-cluster-tcp/src/ClusterNode.php`).

**Impact**

A malicious member has broad data/control authority, including endpoint steering and leave messages.

**Implementation scope**

- Bind per-node certificates to node identity, allowlist endpoints, authorize control events, and segment trust domains.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Use per-node credentials to test endpoint spoofing, forged leave/control events, exposed-actor access, rotation, and trust-domain separation. The concrete implementation contract is: Bind per-node certificates to node identity, allowlist endpoints, authorize control events, and segment trust domains.

**Acceptance criteria**

- [ ] The required action is implemented: Bind per-node certificates to node identity, allowlist endpoints, authorize control events, and segment trust domains.
- [ ] The domain regression evidence passes: Use per-node credentials to test endpoint spoofing, forged leave/control events, exposed-actor access, rotation, and trust-domain separation.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Use per-node credentials to test endpoint spoofing, forged leave/control events, exposed-actor access, rotation, and trust-domain separation.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Bind per-node certificates to node identity, allowlist endpoints, authorize control events, and segment trust domains.
- Update the threat model and secure-configuration guide; provide an upgrade note for stricter defaults or rejected traffic.

**Source audit:** [SEC-008](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 309)

<a id="sec-012"></a>

### SEC-012: Authorize Messenger producer-to-target routes

- [ ] Task status
- **Severity:** Medium
- **Effort:** L
- **Phase:** Phase 2 - Lifecycle and delivery
- **Technical domain:** Security
- **Dependencies:** None

**Problem**

Messenger uses strong type/target allowlists, but producer-controlled stamps select configured actor targets and provenance metadata without per-message origin authorization (`packages/nexus-messenger/src/Routing/StampMessageRouter.php:28-38`).

**Impact**

A producer with publish rights can invoke any registered target and consume capacity.

**Implementation scope**

- Enforce broker ACLs and message-to-target authorization; authenticate envelopes across mutually untrusted producers.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Publish validly encoded messages from authorized and unauthorized identities to each target; verify ACL denial, provenance integrity, and capacity isolation. The concrete implementation contract is: Enforce broker ACLs and message-to-target authorization; authenticate envelopes across mutually untrusted producers.

**Acceptance criteria**

- [ ] The required action is implemented: Enforce broker ACLs and message-to-target authorization; authenticate envelopes across mutually untrusted producers.
- [ ] The domain regression evidence passes: Publish validly encoded messages from authorized and unauthorized identities to each target; verify ACL denial, provenance integrity, and capacity isolation.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Publish validly encoded messages from authorized and unauthorized identities to each target; verify ACL denial, provenance integrity, and capacity isolation.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Enforce broker ACLs and message-to-target authorization; authenticate envelopes across mutually untrusted producers.
- Update the threat model and secure-configuration guide; provide an upgrade note for stricter defaults or rejected traffic.

**Source audit:** [SEC-012](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 313)

<a id="rel-003"></a>

### REL-003: Implement advertised supervision strategies

- [ ] Task status
- **Severity:** High
- **Effort:** XL
- **Phase:** Phase 2 - Lifecycle and delivery
- **Technical domain:** Runtime reliability
- **Dependencies:** [REL-002](#rel-002)

**Problem**

All-for-one never branches on strategy type; `Escalate` becomes stop; backoff parameters are ignored and restart is immediate (`packages/nexus-core/src/Actor/ActorCell.php`, supervision-handling symbols).

**Impact**

Advertised advanced supervision does not behave as configured.

**Implementation scope**

- Implement parent/sibling propagation and scheduled backoff, or reject unsupported strategies.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Cross-runtime contract tests for one-for-one, all-for-one, escalate, retry windows, exponential backoff timing, and sibling/parent effects. The concrete implementation contract is: Implement parent/sibling propagation and scheduled backoff, or reject unsupported strategies.

**Acceptance criteria**

- [ ] The required action is implemented: Implement parent/sibling propagation and scheduled backoff, or reject unsupported strategies.
- [ ] The domain regression evidence passes: Cross-runtime contract tests for one-for-one, all-for-one, escalate, retry windows, exponential backoff timing, and sibling/parent effects.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Cross-runtime contract tests for one-for-one, all-for-one, escalate, retry windows, exponential backoff timing, and sibling/parent effects.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Implement parent/sibling propagation and scheduled backoff, or reject unsupported strategies.
- Publish the cross-runtime lifecycle or delivery contract and call out any changed failure outcome or default.

**Source audit:** [REL-003](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 269)

<a id="rel-004"></a>

### REL-004: Make suspended actors resumable

- [ ] Task status
- **Severity:** Medium
- **Effort:** L
- **Phase:** Phase 2 - Lifecycle and delivery
- **Technical domain:** Runtime reliability
- **Dependencies:** [REL-002](#rel-002)

**Problem**

Suspended actors discard every envelope before system-message dispatch, including `Resume` (`packages/nexus-core/src/Actor/ActorCell.php`; the gap is encoded by `packages/nexus-core/tests/Unit/Actor/ActorCellAdvancedTest.php`).

**Impact**

Documented resume and priority system messages do not work.

**Implementation scope**

- Separate system/user queues or process lifecycle messages before the state guard.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Suspend with queued user traffic, deliver resume/stop system messages, and verify ordering without message loss on every runtime. The concrete implementation contract is: Separate system/user queues or process lifecycle messages before the state guard.

**Acceptance criteria**

- [ ] The required action is implemented: Separate system/user queues or process lifecycle messages before the state guard.
- [ ] The domain regression evidence passes: Suspend with queued user traffic, deliver resume/stop system messages, and verify ordering without message loss on every runtime.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Suspend with queued user traffic, deliver resume/stop system messages, and verify ordering without message loss on every runtime.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Separate system/user queues or process lifecycle messages before the state guard.
- Publish the cross-runtime lifecycle or delivery contract and call out any changed failure outcome or default.

**Source audit:** [REL-004](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 270)

<a id="rel-006"></a>

### REL-006: Guarantee leaf-first bounded shutdown

- [ ] Task status
- **Severity:** High
- **Effort:** XL
- **Phase:** Phase 2 - Lifecycle and delivery
- **Technical domain:** Runtime reliability
- **Dependencies:** [REL-002](#rel-002)

**Problem**

Parent stop enqueues child poison pills, then immediately fires parent `PostStop`; `ActorSystem` waits only for roots (`packages/nexus-core/src/Actor/ActorCell.php`, `packages/nexus-core/src/Actor/ActorSystem.php`).

**Impact**

Parent resources can close while descendants are still working; shutdown is not leaf-first.

**Implementation scope**

- Track descendants and await termination inside one shared deadline.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Build deep actor trees with slow children and verify PostStop order, one shared deadline, timeout reporting, and no resource use after parent closure. The concrete implementation contract is: Track descendants and await termination inside one shared deadline.

**Acceptance criteria**

- [ ] The required action is implemented: Track descendants and await termination inside one shared deadline.
- [ ] The domain regression evidence passes: Build deep actor trees with slow children and verify PostStop order, one shared deadline, timeout reporting, and no resource use after parent closure.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Build deep actor trees with slow children and verify PostStop order, one shared deadline, timeout reporting, and no resource use after parent closure.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Track descendants and await termination inside one shared deadline.
- Publish the cross-runtime lifecycle or delivery contract and call out any changed failure outcome or default.

**Source audit:** [REL-006](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 272)

## Phase 3 - Durable DDD and persistence

<a id="ddd-003"></a>

### DDD-003: Fence persistent aggregate writers

- [ ] Task status
- **Severity:** High
- **Effort:** XL
- **Phase:** Phase 3 - Durable DDD and persistence
- **Technical domain:** Domain model and persistence
- **Dependencies:** None

**Problem**

`ActorSystem::writerId()` is not passed to persistence. Behaviors generate independent ULIDs and replay filtering defaults off (`packages/nexus-core/src/Actor/ActorSystem.php`, `packages/nexus-persistence/src/EventSourced/EventSourcedBehavior.php`, `packages/nexus-persistence/src/EventSourced/PersistenceEngine.php`).

**Impact**

Multiple systems can believe they own one stream. Sequence uniqueness detects some races but does not fence stale writers.

**Implementation scope**

- Define durable ownership with leases/epochs and fencing tokens plus expected-version writes.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Run concurrent stale/current writers against every store adapter and prove epochs reject the stale owner after lease turnover. The concrete implementation contract is: Define durable ownership with leases/epochs and fencing tokens plus expected-version writes.

**Acceptance criteria**

- [ ] The required action is implemented: Define durable ownership with leases/epochs and fencing tokens plus expected-version writes.
- [ ] The domain regression evidence passes: Run concurrent stale/current writers against every store adapter and prove epochs reject the stale owner after lease turnover.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Run concurrent stale/current writers against every store adapter and prove epochs reject the stale owner after lease turnover.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Define durable ownership with leases/epochs and fencing tokens plus expected-version writes.
- Update persistence/DDD guarantees and add a migration note for stored streams or command protocols affected by the change.

**Source audit:** [DDD-003](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 246)

<a id="ddd-005"></a>

### DDD-005: Version and upcast event schemas

- [ ] Task status
- **Severity:** High
- **Effort:** XL
- **Phase:** Phase 3 - Durable DDD and persistence
- **Technical domain:** Domain model and persistence
- **Dependencies:** None

**Problem**

`PersistenceEngine::handlePersist()` sets `eventType: $event::class`; `EventEnvelope` has no schema-version field and no upcaster registry exists (`packages/nexus-persistence/src/EventSourced/PersistenceEngine.php:181-185`, `packages/nexus-persistence/src/Event/EventEnvelope.php`).

**Impact**

Class renames or schema changes can make historical aggregates unrecoverable.

**Implementation scope**

- Add stable event names, schema versions, ordered upcasters, and compatibility fixtures.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Recover compatibility fixtures for every supported historical schema, class rename, unknown type, and failed upcast. The concrete implementation contract is: Add stable event names, schema versions, ordered upcasters, and compatibility fixtures.

**Acceptance criteria**

- [ ] The required action is implemented: Add stable event names, schema versions, ordered upcasters, and compatibility fixtures.
- [ ] The domain regression evidence passes: Recover compatibility fixtures for every supported historical schema, class rename, unknown type, and failed upcast.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Recover compatibility fixtures for every supported historical schema, class rename, unknown type, and failed upcast.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Add stable event names, schema versions, ordered upcasters, and compatibility fixtures.
- Update persistence/DDD guarantees and add a migration note for stored streams or command protocols affected by the change.

**Source audit:** [DDD-005](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 248)

<a id="rel-010"></a>

### REL-010: Persist post-commit effects

- [ ] Task status
- **Severity:** High
- **Effort:** XL
- **Phase:** Phase 3 - Durable DDD and persistence
- **Technical domain:** Runtime reliability
- **Dependencies:** [DDD-001](#ddd-001)

**Problem**

Post-commit replies and commands are volatile and not recovered (`packages/nexus-persistence/src/EventSourced/PersistenceEngine.php`, post-commit effect handling).

**Impact**

Durable state can commit while externally required work is lost.

**Implementation scope**

- Add transactional outbox/inbox or persisted pending effects.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Crash after state commit at every reply/command dispatch boundary and prove outbox/inbox replay plus consumer idempotency. The concrete implementation contract is: Add transactional outbox/inbox or persisted pending effects.

**Acceptance criteria**

- [ ] The required action is implemented: Add transactional outbox/inbox or persisted pending effects.
- [ ] The domain regression evidence passes: Crash after state commit at every reply/command dispatch boundary and prove outbox/inbox replay plus consumer idempotency.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Crash after state commit at every reply/command dispatch boundary and prove outbox/inbox replay plus consumer idempotency.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Add transactional outbox/inbox or persisted pending effects.
- Publish the cross-runtime lifecycle or delivery contract and call out any changed failure outcome or default.

**Source audit:** [REL-010](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 279)

<a id="ddd-002"></a>

### DDD-002: Make saga effects crash-recoverable

- [ ] Task status
- **Severity:** High
- **Effort:** XL
- **Phase:** Phase 3 - Durable DDD and persistence
- **Technical domain:** Domain model and persistence
- **Dependencies:** [DDD-001](#ddd-001), [REL-010](#rel-010)

**Problem**

Recovery inside `PersistenceEngine::create()` only folds events; it never reconstructs `thenRun` callbacks (`packages/nexus-persistence/src/EventSourced/PersistenceEngine.php:86-110`, plus post-commit `handlePersist()`), while `website/docs/guides/saga.md:93-97` promises commands are reissued.

**Impact**

A crash after event commit but before side effect permanently stalls a saga or projection.

**Implementation scope**

- Add a transactional outbox/effect journal or persist pending work explicitly; remove the current guarantee.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Crash at each commit/dispatch boundary and prove pending work is replayed exactly as specified without duplicate external effects. The concrete implementation contract is: Add a transactional outbox/effect journal or persist pending work explicitly; remove the current guarantee.

**Acceptance criteria**

- [ ] The required action is implemented: Add a transactional outbox/effect journal or persist pending work explicitly; remove the current guarantee.
- [ ] The domain regression evidence passes: Crash at each commit/dispatch boundary and prove pending work is replayed exactly as specified without duplicate external effects.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Crash at each commit/dispatch boundary and prove pending work is replayed exactly as specified without duplicate external effects.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Add a transactional outbox/effect journal or persist pending work explicitly; remove the current guarantee.
- Update persistence/DDD guarantees and add a migration note for stored streams or command protocols affected by the change.

**Source audit:** [DDD-002](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 245)

<a id="ddd-004"></a>

### DDD-004: Deduplicate wallet commands

- [ ] Task status
- **Severity:** High
- **Effort:** L
- **Phase:** Phase 3 - Durable DDD and persistence
- **Technical domain:** Domain model and persistence
- **Dependencies:** [DDD-003](#ddd-003)

**Problem**

Wallet commands have no command ID, dedup state, or cached outcome; persistence precedes reply (`examples/nexus-wallet-app/src/Actor/WalletActor.php:80-94`).

**Impact**

A timed-out retry can double-apply a deposit or withdrawal.

**Implementation scope**

- Add idempotency keys, aggregate deduplication, and reply caching guidance.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Retry identical deposit and withdrawal command IDs before reply, after timeout, and after restart; assert one balance change and stable reply. The concrete implementation contract is: Add idempotency keys, aggregate deduplication, and reply caching guidance.

**Acceptance criteria**

- [ ] The required action is implemented: Add idempotency keys, aggregate deduplication, and reply caching guidance.
- [ ] The domain regression evidence passes: Retry identical deposit and withdrawal command IDs before reply, after timeout, and after restart; assert one balance change and stable reply.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Retry identical deposit and withdrawal command IDs before reply, after timeout, and after restart; assert one balance change and stable reply.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Add idempotency keys, aggregate deduplication, and reply caching guidance.
- Update persistence/DDD guarantees and add a migration note for stored streams or command protocols affected by the change.

**Source audit:** [DDD-004](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 247)

<a id="rel-007"></a>

### REL-007: Define Messenger acknowledgement semantics

- [ ] Task status
- **Severity:** High
- **Effort:** XL
- **Phase:** Phase 3 - Durable DDD and persistence
- **Technical domain:** Runtime reliability
- **Dependencies:** [REL-010](#rel-010)

**Problem**

Messenger normal consumption acknowledges after mailbox acceptance, before handler execution or persistence (`packages/nexus-messenger/src/Consumer/ReceiverActor.php:358-408`).

**Impact**

Crash after enqueue loses the broker message despite "at-least-once" wording.

**Implementation scope**

- Rename semantics to ack-on-enqueue or add handler/durable-commit acknowledgement.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Crash before enqueue, after enqueue, during handling, after persistence, and before broker ack; verify redelivery/loss matches the selected mode. The concrete implementation contract is: Rename semantics to ack-on-enqueue or add handler/durable-commit acknowledgement.

**Acceptance criteria**

- [ ] The required action is implemented: Rename semantics to ack-on-enqueue or add handler/durable-commit acknowledgement.
- [ ] The domain regression evidence passes: Crash before enqueue, after enqueue, during handling, after persistence, and before broker ack; verify redelivery/loss matches the selected mode.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Crash before enqueue, after enqueue, during handling, after persistence, and before broker ack; verify redelivery/loss matches the selected mode.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Rename semantics to ack-on-enqueue or add handler/durable-commit acknowledgement.
- Publish the cross-runtime lifecycle or delivery contract and call out any changed failure outcome or default.

**Source audit:** [REL-007](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 273)

<a id="scale-003"></a>

### SCALE-003: Stream recovery within explicit budgets

- [ ] Task status
- **Severity:** High
- **Effort:** XL
- **Phase:** Phase 3 - Durable DDD and persistence
- **Technical domain:** Scaling and transport
- **Dependencies:** [DDD-005](#ddd-005)

**Problem**

Recovery performs synchronous store I/O and materializes histories; replay filtering buffers all events (`packages/nexus-persistence/src/EventSourced/PersistenceEngine.php`, `packages/nexus-persistence/src/Recovery/ReplayFilter.php`).

**Impact**

Long histories block actor startup and can exhaust memory.

**Implementation scope**

- Stream/page recovery, add timeouts/budgets/circuit breakers, and require snapshots for large histories.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Recover large streams with and without snapshots under slow-store, timeout, corrupt-event, and memory-budget scenarios. The concrete implementation contract is: Stream/page recovery, add timeouts/budgets/circuit breakers, and require snapshots for large histories.

**Acceptance criteria**

- [ ] The required action is implemented: Stream/page recovery, add timeouts/budgets/circuit breakers, and require snapshots for large histories.
- [ ] The domain regression evidence passes: Recover large streams with and without snapshots under slow-store, timeout, corrupt-event, and memory-budget scenarios.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Recover large streams with and without snapshots under slow-store, timeout, corrupt-event, and memory-budget scenarios.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Stream/page recovery, add timeouts/budgets/circuit breakers, and require snapshots for large histories.
- Document measured capacity, supported payload/topology limits, overload behavior, and upgrade constraints.

**Source audit:** [SCALE-003](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 278)

<a id="dsl-005"></a>

### DSL-005: Define a real recovery lifecycle

- [ ] Task status
- **Severity:** High
- **Effort:** L
- **Phase:** Phase 3 - Durable DDD and persistence
- **Technical domain:** Public API and DSL
- **Dependencies:** [DDD-002](#ddd-002)

**Problem**

Documentation promises `RecoveryCompleted` and recovery stashing, but no such signal or dispatch exists; recovery is synchronous setup (`website/docs/persistence/event-sourcing.md:82-104`, `packages/nexus-persistence/src/EventSourced/PersistenceEngine.php:86-115`).

**Impact**

Users design lifecycle logic around a nonexistent hook.

**Implementation scope**

- Document synchronous behavior or implement an asynchronous recovery lifecycle and bounded stash.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Cover synchronous or asynchronous recovery completion, command arrival during recovery, bounded stashing, failure, and timeout. The concrete implementation contract is: Document synchronous behavior or implement an asynchronous recovery lifecycle and bounded stash.

**Acceptance criteria**

- [ ] The required action is implemented: Document synchronous behavior or implement an asynchronous recovery lifecycle and bounded stash.
- [ ] The domain regression evidence passes: Cover synchronous or asynchronous recovery completion, command arrival during recovery, bounded stashing, failure, and timeout.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Cover synchronous or asynchronous recovery completion, command arrival during recovery, bounded stashing, failure, and timeout.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Document synchronous behavior or implement an asynchronous recovery lifecycle and bounded stash.
- Update API reference and runnable examples; document the compatibility behavior for callers using the old DSL contract.

**Source audit:** [DSL-005](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 253)

<a id="ops-002"></a>

### OPS-002: Implement snapshot retention semantics

- [ ] Task status
- **Severity:** High
- **Effort:** L
- **Phase:** Phase 3 - Durable DDD and persistence
- **Technical domain:** Operations and observability
- **Dependencies:** [SCALE-003](#scale-003)

**Problem**

`keepSnapshots` is not applied; snapshots are not pruned and events are deleted through the newest snapshot (`packages/nexus-persistence/src/EventSourced/RetentionPolicy.php`, `packages/nexus-persistence/src/EventSourced/PersistenceEngine.php`).

**Impact**

Snapshot storage grows and event history can be deleted more aggressively than documented.

**Implementation scope**

- Implement oldest-retained-snapshot semantics and adapter integration tests.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Across every persistence adapter, create multiple snapshots/events and verify keepSnapshots, deletion boundaries, failed cleanup, and recoverability. The concrete implementation contract is: Implement oldest-retained-snapshot semantics and adapter integration tests.

**Acceptance criteria**

- [ ] The required action is implemented: Implement oldest-retained-snapshot semantics and adapter integration tests.
- [ ] The domain regression evidence passes: Across every persistence adapter, create multiple snapshots/events and verify keepSnapshots, deletion boundaries, failed cleanup, and recoverability.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Across every persistence adapter, create multiple snapshots/events and verify keepSnapshots, deletion boundaries, failed cleanup, and recoverability.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Implement oldest-retained-snapshot semantics and adapter integration tests.
- Update operator runbooks, metrics/alerts, rollout and rollback procedures, and any changed retention/shutdown semantics.

**Source audit:** [OPS-002](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 281)

## Phase 4 - Scaling and operations

<a id="ops-003"></a>

### OPS-003: Reproduce and control Swoole worker cycling

- [ ] Task status
- **Severity:** Medium
- **Effort:** L
- **Phase:** Phase 4 - Scaling and operations
- **Technical domain:** Operations and observability
- **Dependencies:** [REL-006](#rel-006)

**Problem**

Operations docs record unexplained Swoole worker cycling and cases where `WorkerStop` does not fire; shutdown timeout is not mapped to Swoole `max_wait_time` (`website/docs/operations/swoole-deadlock-detector.md:40-74`). This is an unverified operational risk rather than a reproduced framework failure.

**Impact**

If reproduced in deployment, in-memory state/WebSocket sessions can be lost during restarts.

**Implementation scope**

- Root-cause the cycling, configure native shutdown, and run restart/soak tests before production claims.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Run long low-traffic and restart soak tests with WebSockets and actor state; capture WorkerStop, max_wait_time, memory, and shutdown deadlines. The concrete implementation contract is: Root-cause the cycling, configure native shutdown, and run restart/soak tests before production claims.

**Acceptance criteria**

- [ ] The required action is implemented: Root-cause the cycling, configure native shutdown, and run restart/soak tests before production claims.
- [ ] The domain regression evidence passes: Run long low-traffic and restart soak tests with WebSockets and actor state; capture WorkerStop, max_wait_time, memory, and shutdown deadlines.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Run long low-traffic and restart soak tests with WebSockets and actor state; capture WorkerStop, max_wait_time, memory, and shutdown deadlines.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Root-cause the cycling, configure native shutdown, and run restart/soak tests before production claims.
- Update operator runbooks, metrics/alerts, rollout and rollback procedures, and any changed retention/shutdown semantics.

**Source audit:** [OPS-003](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 282)

<a id="ops-004"></a>

### OPS-004: Separate membership floors from write safety

- [ ] Task status
- **Severity:** High
- **Effort:** XL
- **Phase:** Phase 4 - Scaling and operations
- **Technical domain:** Operations and observability
- **Dependencies:** [DDD-003](#ddd-003)

**Problem**

`minimumMembers` suppresses a downing transition; it is not quorum or write fencing (`packages/nexus-cluster-tcp/src/Membership/MembershipService.php:343-405`).

**Impact**

Both partition sides can keep serving and writing.

**Implementation scope**

- Rename the setting and require external consensus/lease fencing for stateful ownership.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Partition a multi-host cluster asymmetrically and prove stateful writes require external quorum/lease fencing rather than minimumMembers. The concrete implementation contract is: Rename the setting and require external consensus/lease fencing for stateful ownership.

**Acceptance criteria**

- [ ] The required action is implemented: Rename the setting and require external consensus/lease fencing for stateful ownership.
- [ ] The domain regression evidence passes: Partition a multi-host cluster asymmetrically and prove stateful writes require external quorum/lease fencing rather than minimumMembers.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Partition a multi-host cluster asymmetrically and prove stateful writes require external quorum/lease fencing rather than minimumMembers.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Rename the setting and require external consensus/lease fencing for stateful ownership.
- Update operator runbooks, metrics/alerts, rollout and rollback procedures, and any changed retention/shutdown semantics.

**Source audit:** [OPS-004](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 283)

<a id="scale-001"></a>

### SCALE-001: Add worker transport admission and liveness

- [ ] Task status
- **Severity:** High
- **Effort:** XL
- **Phase:** Phase 4 - Scaling and operations
- **Technical domain:** Scaling and transport
- **Dependencies:** [REL-009](#rel-009)

**Problem**

Thread transport silently ignores missing workers, has no send result/capacity/depth metric, polls with up to 10 ms idle delay, and directory entries do not unregister (`packages/nexus-worker-pool-swoole/src/Transport/ThreadQueueTransport.php:48-118`).

**Impact**

A stale hash-ring worker becomes a black hole; overload is invisible.

**Implementation scope**

- Add bounded queues, leases/heartbeats, unregister, admission results, and fail-fast pool restart.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Kill, stall, remove, and saturate workers while routing; verify leases expire, queues stay bounded, sends fail visibly, and recovery does not black-hole keys. The concrete implementation contract is: Add bounded queues, leases/heartbeats, unregister, admission results, and fail-fast pool restart.

**Acceptance criteria**

- [ ] The required action is implemented: Add bounded queues, leases/heartbeats, unregister, admission results, and fail-fast pool restart.
- [ ] The domain regression evidence passes: Kill, stall, remove, and saturate workers while routing; verify leases expire, queues stay bounded, sends fail visibly, and recovery does not black-hole keys.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Kill, stall, remove, and saturate workers while routing; verify leases expire, queues stay bounded, sends fail visibly, and recovery does not black-hole keys.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Add bounded queues, leases/heartbeats, unregister, admission results, and fail-fast pool restart.
- Document measured capacity, supported payload/topology limits, overload behavior, and upgrade constraints.

**Source audit:** [SCALE-001](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 276)

<a id="scale-004"></a>

### SCALE-004: Publish multi-host cluster limits

- [ ] Task status
- **Severity:** Medium
- **Effort:** XL
- **Phase:** Phase 4 - Scaling and operations
- **Technical domain:** Scaling and transport
- **Dependencies:** [REL-009](#rel-009), [SEC-007](#sec-007)

**Problem**

TCP cluster is O(N^2); only a 16-node result is cited, with around 50 described as comfortable and redesign beyond 100 (`packages/nexus-cluster-tcp/README.md:52-61`).

**Impact**

The cited topology does not establish multi-host latency, churn, TLS, packet-loss, or partition behavior.

**Implementation scope**

- Treat 16 as demonstrated only under its published setup, 50 as hypothesis; add multi-host chaos/soak tests.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Measure 16/32/50+ nodes across hosts with TLS, churn, packet loss, partitions, slow peers, and representative payloads. The concrete implementation contract is: Treat 16 as demonstrated only under its published setup, 50 as hypothesis; add multi-host chaos/soak tests.

**Acceptance criteria**

- [ ] The required action is implemented: Treat 16 as demonstrated only under its published setup, 50 as hypothesis; add multi-host chaos/soak tests.
- [ ] The domain regression evidence passes: Measure 16/32/50+ nodes across hosts with TLS, churn, packet loss, partitions, slow peers, and representative payloads.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Measure 16/32/50+ nodes across hosts with TLS, churn, packet loss, partitions, slow peers, and representative payloads.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Treat 16 as demonstrated only under its published setup, 50 as hypothesis; add multi-host chaos/soak tests.
- Document measured capacity, supported payload/topology limits, overload behavior, and upgrade constraints.

**Source audit:** [SCALE-004](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 284)

<a id="scale-002"></a>

### SCALE-002: Specify and benchmark thread serialization

- [ ] Task status
- **Severity:** High
- **Effort:** L
- **Phase:** Phase 4 - Scaling and operations
- **Technical domain:** Scaling and transport
- **Dependencies:** [SCALE-001](#scale-001)

**Problem**

Thread queue uses `php_serialize` (`packages/nexus-worker-pool-swoole/src/Transport/ThreadQueueTransport.php`) while docs claim direct object passage without serialization.

**Impact**

Resources, closures, PDO, and coroutine primitives cannot cross; throughput depends on payload shape.

**Implementation scope**

- Validate serializability and benchmark payload sizes; correct documentation.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Round-trip supported payload classes and reject resources/closures; benchmark latency, throughput, CPU, and memory by payload size. The concrete implementation contract is: Validate serializability and benchmark payload sizes; correct documentation.

**Acceptance criteria**

- [ ] The required action is implemented: Validate serializability and benchmark payload sizes; correct documentation.
- [ ] The domain regression evidence passes: Round-trip supported payload classes and reject resources/closures; benchmark latency, throughput, CPU, and memory by payload size.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Round-trip supported payload classes and reject resources/closures; benchmark latency, throughput, CPU, and memory by payload size.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Validate serializability and benchmark payload sizes; correct documentation.
- Document measured capacity, supported payload/topology limits, overload behavior, and upgrade constraints.

**Source audit:** [SCALE-002](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 277)

<a id="ops-005"></a>

### OPS-005: Restore reproducible performance harnesses

- [ ] Task status
- **Severity:** High
- **Effort:** L
- **Phase:** Phase 4 - Scaling and operations
- **Technical domain:** Operations and observability
- **Dependencies:** [SCALE-002](#scale-002), [SCALE-004](#scale-004)

**Problem**

Published cluster benchmark docs reference absent harnesses; the committed performance suite fatally references removed `ConsistentHashRing` (`tests/Performance/ClusterPerformanceTest.php:64`).

**Impact**

Throughput claims are not independently reproducible.

**Implementation scope**

- Repair harnesses, publish environment/raw data, and make performance regression jobs executable.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Run benchmark smoke tests from a clean checkout and reproduce published throughput from checked-in commands, fixtures, and raw results. The concrete implementation contract is: Repair harnesses, publish environment/raw data, and make performance regression jobs executable.

**Acceptance criteria**

- [ ] The required action is implemented: Repair harnesses, publish environment/raw data, and make performance regression jobs executable.
- [ ] The domain regression evidence passes: Run benchmark smoke tests from a clean checkout and reproduce published throughput from checked-in commands, fixtures, and raw results.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Run benchmark smoke tests from a clean checkout and reproduce published throughput from checked-in commands, fixtures, and raw results.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Repair harnesses, publish environment/raw data, and make performance regression jobs executable.
- Update operator runbooks, metrics/alerts, rollout and rollback procedures, and any changed retention/shutdown semantics.

**Source audit:** [OPS-005](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 285)

<a id="qa-003"></a>

### QA-003: Make performance smoke tests executable

- [ ] Task status
- **Severity:** High
- **Effort:** L
- **Phase:** Phase 4 - Scaling and operations
- **Technical domain:** Quality and release engineering
- **Dependencies:** [OPS-005](#ops-005)

**Problem**

Current performance tests are neither a reliable benchmark gate nor executable as committed; `tests/Performance/ClusterPerformanceTest.php:64` references removed `ConsistentHashRing`.

**Impact**

The committed suite cannot detect performance regressions or substantiate published capacity claims.

**Implementation scope**

- Separate correctness performance assertions from benchmark jobs and require a smoke run on every relevant change.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Run a bounded clean-checkout performance smoke job and assert no removed symbols, invalid fixtures, or missing harness assets. The concrete implementation contract is: Separate correctness performance assertions from benchmark jobs and require a smoke run on every relevant change.

**Acceptance criteria**

- [ ] The required action is implemented: Separate correctness performance assertions from benchmark jobs and require a smoke run on every relevant change.
- [ ] The domain regression evidence passes: Run a bounded clean-checkout performance smoke job and assert no removed symbols, invalid fixtures, or missing harness assets.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Run a bounded clean-checkout performance smoke job and assert no removed symbols, invalid fixtures, or missing harness assets.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Separate correctness performance assertions from benchmark jobs and require a smoke run on every relevant change.
- Update contributor and release-gate documentation so the new required check and local reproduction command are explicit.

**Source audit:** [QA-003](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 370)

## Phase 5 - Documentation, examples, packaging, and release assurance

<a id="arch-001"></a>

### ARCH-001: Make split packages stable-installable

- [ ] Task status
- **Severity:** High
- **Effort:** L
- **Phase:** Phase 5 - Documentation, examples, packaging, and release assurance
- **Technical domain:** Architecture and packaging
- **Dependencies:** None

**Problem**

Most split manifests retain internal `dev-main`; tag splitting does not rewrite them (`packages/nexus/composer.json:6-12`, `.github/workflows/split.yml:22-88`).

**Impact**

Tagged stable packages remain coupled to moving branch heads and may not resolve for stable consumers.

**Implementation scope**

- Use `self.version`/release-compatible constraints and install every split package from a clean stable fixture before tagging.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Tag a release candidate, split every package, and install each plus the meta-package in stable-only clean Composer projects. The concrete implementation contract is: Use `self.version`/release-compatible constraints and install every split package from a clean stable fixture before tagging.

**Acceptance criteria**

- [ ] The required action is implemented: Use `self.version`/release-compatible constraints and install every split package from a clean stable fixture before tagging.
- [ ] The domain regression evidence passes: Tag a release candidate, split every package, and install each plus the meta-package in stable-only clean Composer projects.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Tag a release candidate, split every package, and install each plus the meta-package in stable-only clean Composer projects.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Use `self.version`/release-compatible constraints and install every split package from a clean stable fixture before tagging.
- Update package READMEs, dependency diagrams, and release/install guidance; verify split-package compatibility.

**Source audit:** [ARCH-001](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 259)

<a id="arch-002"></a>

### ARCH-002: Enforce runtime-to-core dependency direction

- [ ] Task status
- **Severity:** Medium
- **Effort:** M
- **Phase:** Phase 5 - Documentation, examples, packaging, and release assurance
- **Technical domain:** Architecture and packaging
- **Dependencies:** None

**Problem**

Deptrac permits both Core -> Runtime and Runtime -> Core while the runtime manifest intentionally has no Core dependency (`deptrac.yaml`).

**Impact**

A future monorepo-only cycle can pass boundary analysis but break split installs.

**Implementation scope**

- Forbid Runtime -> Core and compare imports with Composer dependencies.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Run Deptrac and a Composer-import consistency check with an intentional Runtime-to-Core violation fixture. The concrete implementation contract is: Forbid Runtime -> Core and compare imports with Composer dependencies.

**Acceptance criteria**

- [ ] The required action is implemented: Forbid Runtime -> Core and compare imports with Composer dependencies.
- [ ] The domain regression evidence passes: Run Deptrac and a Composer-import consistency check with an intentional Runtime-to-Core violation fixture.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Run Deptrac and a Composer-import consistency check with an intentional Runtime-to-Core violation fixture.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Forbid Runtime -> Core and compare imports with Composer dependencies.
- Update package READMEs, dependency diagrams, and release/install guidance; verify split-package compatibility.

**Source audit:** [ARCH-002](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 260)

<a id="arch-003"></a>

### ARCH-003: Decouple Doctrine adapters from HTTP

- [ ] Task status
- **Severity:** Medium
- **Effort:** L
- **Phase:** Phase 5 - Documentation, examples, packaging, and release assurance
- **Technical domain:** Architecture and packaging
- **Dependencies:** None

**Problem**

Doctrine DBAL/ORM packages require HTTP packages in their core manifests (`packages/nexus-doctrine-dbal/composer.json`, `packages/nexus-doctrine-orm/composer.json`).

**Impact**

CLI/actor-only consumers receive unnecessary HTTP dependencies and ownership is blurred.

**Implementation scope**

- Split HTTP middleware/integration into optional packages.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Install DBAL/ORM actor adapters in a CLI-only fixture and verify no HTTP package is installed unless its integration package is requested. The concrete implementation contract is: Split HTTP middleware/integration into optional packages.

**Acceptance criteria**

- [ ] The required action is implemented: Split HTTP middleware/integration into optional packages.
- [ ] The domain regression evidence passes: Install DBAL/ORM actor adapters in a CLI-only fixture and verify no HTTP package is installed unless its integration package is requested.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Install DBAL/ORM actor adapters in a CLI-only fixture and verify no HTTP package is installed unless its integration package is requested.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Split HTTP middleware/integration into optional packages.
- Update package READMEs, dependency diagrams, and release/install guidance; verify split-package compatibility.

**Source audit:** [ARCH-003](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 261)

<a id="doc-001"></a>

### DOC-001: Make the wallet guide executable and safe

- [ ] Task status
- **Severity:** High
- **Effort:** L
- **Phase:** Phase 5 - Documentation, examples, packaging, and release assurance
- **Technical domain:** Documentation and developer experience
- **Dependencies:** [DDD-003](#ddd-003), [DDD-004](#ddd-004), [SEC-013](#sec-013)

**Problem**

Wallet README describes removed `RequestActor` files, sends `amount` while DTO requires `amountCents`, and defaults each worker to a separate `InMemoryEventStore` (`examples/nexus-wallet-app/README.md:40-80,203-235`, `examples/nexus-wallet-app/src/Boot/WalletApp.php:129-134`, `examples/nexus-wallet-app/src/Http/Request/AmountRequest.php:8-16`).

**Impact**

Adopters can copy a financially themed example that uses stale files, invalid payloads, and unsafe per-worker state.

**Implementation scope**

- Make it single-worker or shared persistent/affinity-safe; fix payloads and file map; label financial limitations.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Execute the documented wallet requests and file map in single/multi-worker modes; assert payload names and stated durability constraints. The concrete implementation contract is: Make it single-worker or shared persistent/affinity-safe; fix payloads and file map; label financial limitations.

**Acceptance criteria**

- [ ] The required action is implemented: Make it single-worker or shared persistent/affinity-safe; fix payloads and file map; label financial limitations.
- [ ] The domain regression evidence passes: Execute the documented wallet requests and file map in single/multi-worker modes; assert payload names and stated durability constraints.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Execute the documented wallet requests and file map in single/multi-worker modes; assert payload names and stated durability constraints.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Make it single-worker or shared persistent/affinity-safe; fix payloads and file map; label financial limitations.
- Keep the page executable in CI and add an explicit maturity/compatibility note wherever implementation remains constrained.

**Source audit:** [DOC-001](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 344)

<a id="doc-002"></a>

### DOC-002: Align event-sourcing documentation with code

- [ ] Task status
- **Severity:** High
- **Effort:** L
- **Phase:** Phase 5 - Documentation, examples, packaging, and release assurance
- **Technical domain:** Documentation and developer experience
- **Dependencies:** [DDD-001](#ddd-001), [DDD-003](#ddd-003), [DSL-004](#dsl-004), [DSL-005](#dsl-005)

**Problem**

Event sourcing docs show the wrong handler order, nonexistent `RecoveryCompleted`, working `none()->thenReply`, side effects after `none`, and automatic system writer ID (`website/docs/persistence/event-sourcing.md:30-63,82-157`).

**Impact**

The flagship persistence guide teaches APIs and guarantees that fail or do not exist.

**Implementation scope**

- Correct docs only after the underlying contracts are fixed or explicitly document current semantics.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Compile and execute every event-sourcing page snippet against the final handler, effect, recovery, and writer-identity contracts. The concrete implementation contract is: Correct docs only after the underlying contracts are fixed or explicitly document current semantics.

**Acceptance criteria**

- [ ] The required action is implemented: Correct docs only after the underlying contracts are fixed or explicitly document current semantics.
- [ ] The domain regression evidence passes: Compile and execute every event-sourcing page snippet against the final handler, effect, recovery, and writer-identity contracts.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Compile and execute every event-sourcing page snippet against the final handler, effect, recovery, and writer-identity contracts.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Correct docs only after the underlying contracts are fixed or explicitly document current semantics.
- Keep the page executable in CI and add an explicit maturity/compatibility note wherever implementation remains constrained.

**Source audit:** [DOC-002](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 345)

<a id="doc-003"></a>

### DOC-003: Replace the saga guide with an executable model

- [ ] Task status
- **Severity:** High
- **Effort:** L
- **Phase:** Phase 5 - Documentation, examples, packaging, and release assurance
- **Technical domain:** Documentation and developer experience
- **Dependencies:** [DDD-002](#ddd-002), [DSL-004](#dsl-004)

**Problem**

Saga guide is skipped from verification and contains wrong handler order, uncaptured refs in a static closure, and false replay/reissue guarantee (`website/docs/guides/saga.md:16-97`).

**Impact**

The current guide can neither execute as written nor deliver its promised crash recovery.

**Implementation scope**

- Replace with an executable outbox/pending-intent example.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Run the complete saga through success, restart, commit-before-dispatch crash, duplicate delivery, and compensation scenarios. The concrete implementation contract is: Replace with an executable outbox/pending-intent example.

**Acceptance criteria**

- [ ] The required action is implemented: Replace with an executable outbox/pending-intent example.
- [ ] The domain regression evidence passes: Run the complete saga through success, restart, commit-before-dispatch crash, duplicate delivery, and compensation scenarios.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Run the complete saga through success, restart, commit-before-dispatch crash, duplicate delivery, and compensation scenarios.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Replace with an executable outbox/pending-intent example.
- Keep the page executable in CI and add an explicit maturity/compatibility note wherever implementation remains constrained.

**Source audit:** [DOC-003](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 346)

<a id="doc-004"></a>

### DOC-004: Describe replay filtering without false repair claims

- [ ] Task status
- **Severity:** Medium
- **Effort:** S
- **Phase:** Phase 5 - Documentation, examples, packaging, and release assurance
- **Technical domain:** Documentation and developer experience
- **Dependencies:** None

**Problem**

`RepairByDiscardOld` docs say events are permanently dropped; implementation only filters the current in-memory replay list (`website/docs/persistence/single-writer.md:64-75`, `packages/nexus-persistence/src/Recovery/ReplayFilter.php`).

**Impact**

Operators may believe corrupt or conflicting stored events were permanently repaired when only one replay was filtered.

**Implementation scope**

- Describe exact behavior and remove "repair" language until storage mutation is explicit.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Verify documentation examples against in-memory filtering and persisted storage state before and after recovery. The concrete implementation contract is: Describe exact behavior and remove "repair" language until storage mutation is explicit.

**Acceptance criteria**

- [ ] The required action is implemented: Describe exact behavior and remove "repair" language until storage mutation is explicit.
- [ ] The domain regression evidence passes: Verify documentation examples against in-memory filtering and persisted storage state before and after recovery.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Verify documentation examples against in-memory filtering and persisted storage state before and after recovery.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Describe exact behavior and remove "repair" language until storage mutation is explicit.
- Keep the page executable in CI and add an explicit maturity/compatibility note wherever implementation remains constrained.

**Source audit:** [DOC-004](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 347)

<a id="doc-005"></a>

### DOC-005: Publish one generated Swoole requirement

- [ ] Task status
- **Severity:** Medium
- **Effort:** M
- **Phase:** Phase 5 - Documentation, examples, packaging, and release assurance
- **Technical domain:** Documentation and developer experience
- **Dependencies:** None

**Problem**

Swoole requirements disagree: installation says 5.0+, the root README says 6.0+, and runtime/server manifests require 6.2.1+ (`website/docs/getting-started/installation.md:18`, `README.md:107,155`, `packages/nexus-runtime-swoole/composer.json`, `packages/nexus-http-server-swoole/composer.json`).

**Impact**

Conflicting version requirements cause failed installs and make the supported platform impossible to determine.

**Implementation scope**

- Generate requirements from package constraints and test install matrices.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Run installation fixtures at the minimum supported Swoole/PHP versions and fail CI when docs diverge from Composer constraints. The concrete implementation contract is: Generate requirements from package constraints and test install matrices.

**Acceptance criteria**

- [ ] The required action is implemented: Generate requirements from package constraints and test install matrices.
- [ ] The domain regression evidence passes: Run installation fixtures at the minimum supported Swoole/PHP versions and fail CI when docs diverge from Composer constraints.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Run installation fixtures at the minimum supported Swoole/PHP versions and fail CI when docs diverge from Composer constraints.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Generate requirements from package constraints and test install matrices.
- Keep the page executable in CI and add an explicit maturity/compatibility note wherever implementation remains constrained.

**Source audit:** [DOC-005](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 348)

<a id="doc-006"></a>

### DOC-006: Fix documentation snippet bootstrapping

- [ ] Task status
- **Severity:** High
- **Effort:** M
- **Phase:** Phase 5 - Documentation, examples, packaging, and release assurance
- **Technical domain:** Documentation and developer experience
- **Dependencies:** None

**Problem**

Documentation verifier prepends `<?php` to every snippet even when the snippet already contains `<?php` (`bin/verify-doc-snippets:113-121`). Of 580 PHP fences, 83 contain open tags and 59 are full-verification fences; the exact concatenation fails lint with an unexpected `<`.

**Impact**

A broken verifier gives false confidence and rejects valid tagged examples before analysis.

**Implementation scope**

- Strip an existing open tag or do not prepend one; add verifier self-tests and a fast batch mode.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Verifier self-tests cover snippets with/without open tags, full/partial modes, namespaces, strict_types, and expected-invalid fences. The concrete implementation contract is: Strip an existing open tag or do not prepend one; add verifier self-tests and a fast batch mode.

**Acceptance criteria**

- [ ] The required action is implemented: Strip an existing open tag or do not prepend one; add verifier self-tests and a fast batch mode.
- [ ] The domain regression evidence passes: Verifier self-tests cover snippets with/without open tags, full/partial modes, namespaces, strict_types, and expected-invalid fences.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Verifier self-tests cover snippets with/without open tags, full/partial modes, namespaces, strict_types, and expected-invalid fences.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Strip an existing open tag or do not prepend one; add verifier self-tests and a fast batch mode.
- Keep the page executable in CI and add an explicit maturity/compatibility note wherever implementation remains constrained.

**Source audit:** [DOC-006](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 349)

<a id="doc-010"></a>

### DOC-010: Define a genuinely comprehensive test command

- [ ] Task status
- **Severity:** Medium
- **Effort:** M
- **Phase:** Phase 5 - Documentation, examples, packaging, and release assurance
- **Technical domain:** Documentation and developer experience
- **Dependencies:** None

**Problem**

Root `make test` is described as all tests but excludes Swoole, real cluster, Doctrine Swoole, HTTP Swoole, and performance suites (`Makefile:25-66`, `website/docs/getting-started/installation.md:88-96`).

**Impact**

Contributors can receive a green "all tests" result while major runtimes and integration suites never ran.

**Implementation scope**

- Rename it or create a truly comprehensive aggregate target.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Enumerate every PHPUnit suite/runtime integration and verify the aggregate command either runs each or explicitly reports an environmental skip. The concrete implementation contract is: Rename it or create a truly comprehensive aggregate target.

**Acceptance criteria**

- [ ] The required action is implemented: Rename it or create a truly comprehensive aggregate target.
- [ ] The domain regression evidence passes: Enumerate every PHPUnit suite/runtime integration and verify the aggregate command either runs each or explicitly reports an environmental skip.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Enumerate every PHPUnit suite/runtime integration and verify the aggregate command either runs each or explicitly reports an environmental skip.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Rename it or create a truly comprehensive aggregate target.
- Keep the page executable in CI and add an explicit maturity/compatibility note wherever implementation remains constrained.

**Source audit:** [DOC-010](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 353)

<a id="qa-001"></a>

### QA-001: Make risk-based coverage thresholds blocking

- [ ] Task status
- **Severity:** Medium
- **Effort:** L
- **Phase:** Phase 5 - Documentation, examples, packaging, and release assurance
- **Technical domain:** Quality and release engineering
- **Dependencies:** [REL-002](#rel-002), [DDD-001](#ddd-001)

**Problem**

Coverage is collected, but the coverage guard is `continue-on-error: true` with a TODO for central actor internals (`.github/workflows/ci.yml:128-144`).

**Impact**

Coverage regressions in the highest-risk runtime paths do not currently block merges.

**Implementation scope**

- Define risk-based thresholds and make the guard blocking after closing the stated core gaps.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Delete or mutate covered core branches to prove package/risk thresholds fail CI, including central actor internals. The concrete implementation contract is: Define risk-based thresholds and make the guard blocking after closing the stated core gaps.

**Acceptance criteria**

- [ ] The required action is implemented: Define risk-based thresholds and make the guard blocking after closing the stated core gaps.
- [ ] The domain regression evidence passes: Delete or mutate covered core branches to prove package/risk thresholds fail CI, including central actor internals.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Delete or mutate covered core branches to prove package/risk thresholds fail CI, including central actor internals.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Define risk-based thresholds and make the guard blocking after closing the stated core gaps.
- Update contributor and release-gate documentation so the new required check and local reproduction command are explicit.

**Source audit:** [QA-001](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 368)

<a id="qa-004"></a>

### QA-004: Include auth and toolkit suites in required CI

- [ ] Task status
- **Severity:** Medium
- **Effort:** M
- **Phase:** Phase 5 - Documentation, examples, packaging, and release assurance
- **Technical domain:** Quality and release engineering
- **Dependencies:** None

**Problem**

Primary CI omits HTTP auth/toolkit test directories even though those packages protect external input (`phpunit.xml:9-39`, `.github/workflows/ci.yml`).

**Impact**

External-input security code can regress while the primary named suites remain green.

**Implementation scope**

- Add the directories to named suites and run negative security cases against real adapters.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Add a deliberately failing HTTP auth/toolkit test and prove every required CI entry point reports it. The concrete implementation contract is: Add the directories to named suites and run negative security cases against real adapters.

**Acceptance criteria**

- [ ] The required action is implemented: Add the directories to named suites and run negative security cases against real adapters.
- [ ] The domain regression evidence passes: Add a deliberately failing HTTP auth/toolkit test and prove every required CI entry point reports it.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Add a deliberately failing HTTP auth/toolkit test and prove every required CI entry point reports it.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Add the directories to named suites and run negative security cases against real adapters.
- Update contributor and release-gate documentation so the new required check and local reproduction command are explicit.

**Source audit:** [QA-004](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 371)

<a id="qa-005"></a>

### QA-005: Restore Psalm level 1 on an immutable commit

- [ ] Task status
- **Severity:** Medium
- **Effort:** L
- **Phase:** Phase 5 - Documentation, examples, packaging, and release assurance
- **Technical domain:** Quality and release engineering
- **Dependencies:** None

**Problem**

The audit-time dirty worktree fails Psalm with 21 errors while README/product claims emphasize Psalm level 1. This result must not be attributed to clean `origin/main` because concurrent uncommitted work appeared during the audit.

**Impact**

The audit-time tree cannot substantiate its level-1 type-safety claim while static analysis reports errors.

**Implementation scope**

- Finish the suppression-removal work, run Psalm on an immutable commit, and keep it blocking in CI.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Run uncached Psalm on the committed candidate and add negative protocol fixtures that demonstrate the advertised generic checks. The concrete implementation contract is: Finish the suppression-removal work, run Psalm on an immutable commit, and keep it blocking in CI.

**Acceptance criteria**

- [ ] The required action is implemented: Finish the suppression-removal work, run Psalm on an immutable commit, and keep it blocking in CI.
- [ ] The domain regression evidence passes: Run uncached Psalm on the committed candidate and add negative protocol fixtures that demonstrate the advertised generic checks.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Run uncached Psalm on the committed candidate and add negative protocol fixtures that demonstrate the advertised generic checks.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Finish the suppression-removal work, run Psalm on an immutable commit, and keep it blocking in CI.
- Update contributor and release-gate documentation so the new required check and local reproduction command are explicit.

**Source audit:** [QA-005](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 372)

<a id="doc-007"></a>

### DOC-007: Make snippet verification a practical CI gate

- [ ] Task status
- **Severity:** High
- **Effort:** L
- **Phase:** Phase 5 - Documentation, examples, packaging, and release assurance
- **Technical domain:** Documentation and developer experience
- **Dependencies:** [DOC-006](#doc-006)

**Problem**

`docs-verify` is not called by any GitHub workflow. It would run Psalm with `--no-cache` separately for about 480 snippets, making it impractical as a gate (`bin/verify-doc-snippets:132-151`; `.github/workflows/pages-docs.yml:31-33`).

**Impact**

Hundreds of uncached analyzer processes keep documentation correctness outside required CI.

**Implementation scope**

- Batch generated snippets into one analysis project, cache Psalm, and make verification required.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Measure a cached/batched full documentation run, inject a broken snippet, and prove required CI fails within the agreed budget. The concrete implementation contract is: Batch generated snippets into one analysis project, cache Psalm, and make verification required.

**Acceptance criteria**

- [ ] The required action is implemented: Batch generated snippets into one analysis project, cache Psalm, and make verification required.
- [ ] The domain regression evidence passes: Measure a cached/batched full documentation run, inject a broken snippet, and prove required CI fails within the agreed budget.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Measure a cached/batched full documentation run, inject a broken snippet, and prove required CI fails within the agreed budget.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Batch generated snippets into one analysis project, cache Psalm, and make verification required.
- Keep the page executable in CI and add an explicit maturity/compatibility note wherever implementation remains constrained.

**Source audit:** [DOC-007](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 350)

<a id="doc-008"></a>

### DOC-008: Document every split package locally

- [ ] Task status
- **Severity:** Medium
- **Effort:** L
- **Phase:** Phase 5 - Documentation, examples, packaging, and release assurance
- **Technical domain:** Documentation and developer experience
- **Dependencies:** [ARCH-001](#arch-001)

**Problem**

Only 23 of 40 package directories have package-local READMEs; 17 important packages rely solely on central docs (BASE tree count reproduced below).

**Impact**

Seventeen split packages lack the local information consumers expect on package registries.

**Implementation scope**

- Generate or maintain minimal install/usage/compatibility READMEs for every split package.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: For all 40 package directories, validate a README template with install, purpose, minimal usage, requirements, maturity, and compatibility links. The concrete implementation contract is: Generate or maintain minimal install/usage/compatibility READMEs for every split package.

**Acceptance criteria**

- [ ] The required action is implemented: Generate or maintain minimal install/usage/compatibility READMEs for every split package.
- [ ] The domain regression evidence passes: For all 40 package directories, validate a README template with install, purpose, minimal usage, requirements, maturity, and compatibility links.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- For all 40 package directories, validate a README template with install, purpose, minimal usage, requirements, maturity, and compatibility links.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Generate or maintain minimal install/usage/compatibility READMEs for every split package.
- Keep the page executable in CI and add an explicit maturity/compatibility note wherever implementation remains constrained.

**Source audit:** [DOC-008](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 351)

<a id="doc-009"></a>

### DOC-009: Generate release documentation from manifests

- [ ] Task status
- **Severity:** High
- **Effort:** M
- **Phase:** Phase 5 - Documentation, examples, packaging, and release assurance
- **Technical domain:** Documentation and developer experience
- **Dependencies:** [ARCH-001](#arch-001)

**Problem**

Release guide says 14 packages and `^1.0` internal constraints, while split matrix contains 37 entries and manifests mostly use `dev-main` (`website/docs/contributing/release-process.md:11,22-55`).

**Impact**

Maintainers following the stale guide can publish an incomplete or unresolvable release.

**Implementation scope**

- Rewrite release documentation from executable manifest/matrix checks.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Compare documented package count and constraints with the split matrix/manifests in CI and rehearse the documented release commands. The concrete implementation contract is: Rewrite release documentation from executable manifest/matrix checks.

**Acceptance criteria**

- [ ] The required action is implemented: Rewrite release documentation from executable manifest/matrix checks.
- [ ] The domain regression evidence passes: Compare documented package count and constraints with the split matrix/manifests in CI and rehearse the documented release commands.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Compare documented package count and constraints with the split matrix/manifests in CI and rehearse the documented release commands.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Rewrite release documentation from executable manifest/matrix checks.
- Keep the page executable in CI and add an explicit maturity/compatibility note wherever implementation remains constrained.

**Source audit:** [DOC-009](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 352)

<a id="dsl-010"></a>

### DSL-010: Make protocol typing claims reproducible

- [ ] Task status
- **Severity:** Medium
- **Effort:** M
- **Phase:** Phase 5 - Documentation, examples, packaging, and release assurance
- **Technical domain:** Public API and DSL
- **Dependencies:** [QA-005](#qa-005)

**Problem**

`ActorRef<T>` is a Psalm docblock generic; runtime accepts `object`, the meta-package omits the Psalm plugin, and quick start erases protocols to `object` (`packages/nexus-core/src/Actor/ActorRef.php:41`, `packages/nexus/composer.json:6-12`, `website/docs/getting-started/quick-start.md:70-85`).

**Impact**

"Zero compromises on type safety" is true only with optional tooling and disciplined annotations.

**Implementation scope**

- Say "Psalm-assisted"; ship plugin config; type-check all examples and `ask`/reply paths.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Run the Psalm plugin over quick-start and ask/reply fixtures, including wrong command and wrong reply negative cases. The concrete implementation contract is: Say "Psalm-assisted"; ship plugin config; type-check all examples and `ask`/reply paths.

**Acceptance criteria**

- [ ] The required action is implemented: Say "Psalm-assisted"; ship plugin config; type-check all examples and `ask`/reply paths.
- [ ] The domain regression evidence passes: Run the Psalm plugin over quick-start and ask/reply fixtures, including wrong command and wrong reply negative cases.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Run the Psalm plugin over quick-start and ask/reply fixtures, including wrong command and wrong reply negative cases.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Say "Psalm-assisted"; ship plugin config; type-check all examples and `ask`/reply paths.
- Update API reference and runnable examples; document the compatibility behavior for callers using the old DSL contract.

**Source audit:** [DSL-010](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 258)

<a id="qa-002"></a>

### QA-002: Restore blocking mutation assurance

- [ ] Task status
- **Severity:** Medium
- **Effort:** L
- **Phase:** Phase 5 - Documentation, examples, packaging, and release assurance
- **Technical domain:** Quality and release engineering
- **Dependencies:** [QA-001](#qa-001)

**Problem**

Mutation testing is also `continue-on-error: true` (`.github/workflows/ci.yml:209-240`).

**Impact**

Tests can exercise lines without detecting behavior regressions, while the mutation job is allowed to fail.

**Implementation scope**

- Restore compatible mutation tooling and make an agreed mutation score blocking for core/persistence/security packages.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Seed known surviving mutants in core, persistence, and security packages and prove agreed per-package MSI gates fail CI. The concrete implementation contract is: Restore compatible mutation tooling and make an agreed mutation score blocking for core/persistence/security packages.

**Acceptance criteria**

- [ ] The required action is implemented: Restore compatible mutation tooling and make an agreed mutation score blocking for core/persistence/security packages.
- [ ] The domain regression evidence passes: Seed known surviving mutants in core, persistence, and security packages and prove agreed per-package MSI gates fail CI.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Seed known surviving mutants in core, persistence, and security packages and prove agreed per-package MSI gates fail CI.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Restore compatible mutation tooling and make an agreed mutation score blocking for core/persistence/security packages.
- Update contributor and release-gate documentation so the new required check and local reproduction command are explicit.

**Source audit:** [QA-002](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 369)

<a id="qa-006"></a>

### QA-006: Cover first-party symbols in architecture checks

- [ ] Task status
- **Severity:** Medium
- **Effort:** L
- **Phase:** Phase 5 - Documentation, examples, packaging, and release assurance
- **Technical domain:** Quality and release engineering
- **Dependencies:** [ARCH-002](#arch-002)

**Problem**

Deptrac reports 0 violations but 1,023 uncovered tokens, and its rules allow at least one package-direction mismatch (`deptrac.yaml`; command and output summarized in [Verification Record](./2026-07-16-nexus-independent-audit.md#verification-record)).

**Impact**

A zero-violation report can conceal more than one thousand unclassified symbols and package-direction mistakes.

**Implementation scope**

- Classify uncovered project symbols, fail on uncovered first-party namespaces, and align rules with Composer manifests.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Fail Deptrac on an uncovered first-party namespace and on imports absent from the consuming Composer manifest. The concrete implementation contract is: Classify uncovered project symbols, fail on uncovered first-party namespaces, and align rules with Composer manifests.

**Acceptance criteria**

- [ ] The required action is implemented: Classify uncovered project symbols, fail on uncovered first-party namespaces, and align rules with Composer manifests.
- [ ] The domain regression evidence passes: Fail Deptrac on an uncovered first-party namespace and on imports absent from the consuming Composer manifest.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Fail Deptrac on an uncovered first-party namespace and on imports absent from the consuming Composer manifest.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Classify uncovered project symbols, fail on uncovered first-party namespaces, and align rules with Composer manifests.
- Update contributor and release-gate documentation so the new required check and local reproduction command are explicit.

**Source audit:** [QA-006](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 373)

<a id="sec-014"></a>

### SEC-014: Harden release supply-chain inputs

- [ ] Task status
- **Severity:** Medium
- **Effort:** XL
- **Phase:** Phase 5 - Documentation, examples, packaging, and release assurance
- **Technical domain:** Security
- **Dependencies:** [ARCH-001](#arch-001)

**Problem**

PHP lockfile is ignored; CI runs no dependency audits; split workflow downloads an unchecked binary and executes it with a split PAT; actions/forks use mutable tags/branches (`.gitignore:3`, `.github/workflows/split.yml:67-88`).

**Impact**

Non-reproducible dependencies and compromised build inputs can affect all split repos/releases.

**Implementation scope**

- Commit/review lock policy, add Composer/npm/OSV audits, pin SHAs, verify checksums, narrow permissions/PAT, and publish SBOM/provenance.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Clean release rehearsal verifies locked dependencies, audits, action/tool SHA pins, checksums, least-privilege tokens, SBOM, and provenance. The concrete implementation contract is: Commit/review lock policy, add Composer/npm/OSV audits, pin SHAs, verify checksums, narrow permissions/PAT, and publish SBOM/provenance.

**Acceptance criteria**

- [ ] The required action is implemented: Commit/review lock policy, add Composer/npm/OSV audits, pin SHAs, verify checksums, narrow permissions/PAT, and publish SBOM/provenance.
- [ ] The domain regression evidence passes: Clean release rehearsal verifies locked dependencies, audits, action/tool SHA pins, checksums, least-privilege tokens, SBOM, and provenance.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Clean release rehearsal verifies locked dependencies, audits, action/tool SHA pins, checksums, least-privilege tokens, SBOM, and provenance.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Commit/review lock policy, add Composer/npm/OSV audits, pin SHAs, verify checksums, narrow permissions/PAT, and publish SBOM/provenance.
- Update the threat model and secure-configuration guide; provide an upgrade note for stricter defaults or rejected traffic.

**Source audit:** [SEC-014](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 315)

<a id="sec-015"></a>

### SEC-015: Run security packages in primary CI

- [ ] Task status
- **Severity:** Medium
- **Effort:** M
- **Phase:** Phase 5 - Documentation, examples, packaging, and release assurance
- **Technical domain:** Security
- **Dependencies:** [QA-004](#qa-004)

**Problem**

Root `unit` suite omits HTTP auth/toolkit directories (`phpunit.xml:9-39`); CI invokes only named root suites.

**Impact**

Security packages can regress while primary CI stays green.

**Implementation scope**

- Include both suites and add explicit negative security regression tests.
- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.

**Technical guidance**

Design the implementation around this finding-specific verification contract: Introduce failing auth/toolkit negative tests and prove the required root CI job detects them. The concrete implementation contract is: Include both suites and add explicit negative security regression tests.

**Acceptance criteria**

- [ ] The required action is implemented: Include both suites and add explicit negative security regression tests.
- [ ] The domain regression evidence passes: Introduce failing auth/toolkit negative tests and prove the required root CI job detects them.
- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.

**Required tests**

- Introduce failing auth/toolkit negative tests and prove the required root CI job detects them.
- Run the smallest affected package suites plus the repository-required static analysis and style gates.

**Documentation and compatibility**

- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: Include both suites and add explicit negative security regression tests.
- Update the threat model and secure-configuration guide; provide an upgrade note for stricter defaults or rejected traffic.

**Source audit:** [SEC-015](./2026-07-16-nexus-independent-audit.md#consolidated-findings) (canonical table row near source line 316)

