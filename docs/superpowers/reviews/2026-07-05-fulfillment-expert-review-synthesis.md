# Expert Review Synthesis — nexus-fulfillment (milestones 1–3) + Nexus DSL

**Date:** 2026-07-05
**Reviews:** three independent expert passes over the example and the framework surface it exercises —
[DDD architect](expert-review-ddd.md) (Evans/Verraes/Fowler/Noback lens),
[distributed-systems expert](expert-review-distsys.md) (Akka/Orleans-veteran lens),
[framework-DSL critic](expert-review-dsl.md) (API-design lens, mining the three-milestone friction log).

## Executive verdict

The example's domain layer, VO discipline, rejection-as-event commitment, and honesty about its
documented races are genuinely strong — all three reviewers said so. But the reviews converge on a
hard conclusion: **the example currently teaches the safe 50% of an actor system while silently
inheriting fail-silent framework defaults for the dangerous 50%** (supervision, mailboxes, writer
identity), and the aggregate/engine state contract has one interaction bug severe enough to corrupt
inventory under ordinary production conditions.

## The finding that trumps everything: restart double-apply corruption

Independently reached by the DDD review (as a design trap) and the distsys review (as a concrete
corruption scenario):

- `EventSourcedBehavior::create($persistenceId, $emptyState, …)` captures an aggregate **instance**.
- Aggregates are mutable; `apply()` mutates that instance in place.
- On supervised restart (default strategy: restart on any `Throwable` — e.g. a transient Doctrine
  failure mid-persist), recovery replays the journal **onto the already-mutated instance**.
- Result: every event applies twice; a SKU's on-hand stock silently doubles — and the corrupted
  state is then journaled forward. No snapshot exists before event 50 to mask it.

The DDD review's `record()`-without-`apply()` critique (aggregate methods must reason about
pre-command state; the `count($pending)===1` dance in `FulfillmentProcess::confirmReservation` is
the visible crack) is the same root: **the engine's state contract (fold-from-empty, instance
reuse) leaks into aggregate authorship and breaks under restart.**

**Fix direction (framework):** `emptyState` must be a factory closure (or the engine must
clone/reconstruct on recovery), and recovery must provably start from empty state. This is a
`nexus-persistence` change with a package test pinning restart-recovery correctness.

## Convergent critical/important findings

| Theme | DDD | DistSys | DSL |
|---|---|---|---|
| Aggregate/engine state contract | Critical 1.1 (record-not-apply trap) | Critical (restart double-apply) | High (generic erasure forces the design) |
| Fail-silent defaults vs "fail-fast" claim | Critical 7.1 (Domain purity unenforced — Deptrac whitelists Nexus) | Critical ×2 (projector death freezes reads; single-writer unwired, `ReplayFilter` actually `off()` with a docblock claiming `fail()`) | Root-cause theme A (dominant): `Effect::none()->thenRun` no-op, `Escalate`→`Stop` silently, `AuthorizationMiddleware` fail-open on closures |
| Rejection persistence semantics | Critical 5.1 (phantom journals for never-created aggregates) + 5.2 (rejections not retry-idempotent) | Important (Restock retry doubles stock) | — |
| Test/production divergence | — | (poison-message and mailbox-drop scenarios untested) | High (`InMemoryEventStore` never serializes → wire-name trap invisible to tests) |
| Scaling honesty | — | Important (`WORKERS=2` corrupts, warned only in a compose comment) | — |
| M4 blockers to fix BEFORE journals freeze | Important 2.2 (`FulfillmentCompleted` naming), 6.1 (compensated-on-intent) | Important (no scheduleOnce deadlines — persist deadline + check-on-wake; no `onRecoveryCompleted` hook; no global event feed → blocks M7 rebuild) | — |

## Prioritized action plan

### Wave 1 — correctness hotfixes (before or opening M4)
1. **Framework:** empty-state factory / recovery-from-empty guarantee in `PersistenceEngine` + restart-recovery package test (the double-apply killer).
2. **Example:** wire real supervision (backoff for entities/projector/manager per the original spec), bounded mailboxes on entities, `withWriterId`(stable) + `ReplayFilter::fail()`; fix the `EventSourcedBehavior` docblock lie.
3. **Example:** don't persist rejections for `NotCreated` aggregates (reply-only there); make `Restock` idempotent (command id or reply-echo).
4. **Example:** Deptrac `Domain` ruleset drops the blanket `Nexus` allowance (Domain needs none of it post-Amendment-C — verify and fence).
5. **Rename before journals freeze:** `FulfillmentCompleted` → reservation-scoped name; move journal-persisted prose ("milestone 4", "api cancel") out of reasons (Reason codes VO).

### Wave 2 — M4 plan inputs (add to the two pre-adjudicated openers)
- PickTask deadlines: persist deadline + check-on-wake + read-model sweeper (NOT bare `scheduleOnce`).
- Contracts/journal split for Orders/Inventory (journals currently ARE the published contracts; Fulfillment models it right).
- README Known-limitations gains: WORKERS=2 corruption, stranded-saga permanence, projector-death mode.
- Reply derivation: explicit `Decision` return from the drain instead of first-RejectionEvent-wins scan.

### Wave 3 — framework roadmap (from the DSL catalog: 28 verified weaknesses — 3 Critical, 11 High)
Root causes to design against: **(A) fail-silent defaults** (make None+thenRun throw, Escalate warn
or work, fail-closed authorization); **(B) one-message-type actor typing** vs union protocols;
**(C) missing last-mile compositions** (sharded entity refs, pub/sub, pooled+wire-named stores,
dead-letter query, declarative router — the example hand-built all five; `withSignalHandler`/`withEventPublisher`
already graduated, `AggregateRoot`+`AggregateBehavior` are next); **(D) leaky positional/wrapper
composition** (12-param engine, wrapper signal stripping, `|` vs PersistenceId grammar);
**(E) docs drift** (`ask()` signature in CLAUDE.md, the `Effect` docblock teaching the no-op).

## What all three reviewers praised

Value-object discipline end to end; the aggregate-as-consistency-boundary insight matched to
actor-per-entity; idempotent decision tables; dedup read-model folds; honest race documentation;
the live-E2E verification culture; wire-name registry discipline.
