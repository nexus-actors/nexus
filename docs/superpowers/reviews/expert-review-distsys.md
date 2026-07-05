# Expert Review — nexus-fulfillment: Concurrency, Distribution, Operations

Reviewer stance: adversarial, Akka/Orleans/Erlang operational experience. All paths relative to
`/Users/tomas/Work/Monadial/CodeOSS/nexus/.claude/worktrees/fulfillment/` unless absolute.
Example root: `examples/nexus-fulfillment/`.

---

## Verdict

This is an unusually honest teaching example — the three documented limitations (at-most-once bus,
user-cancel race, compensation sub-race) are real, correctly characterized, and their mitigations
are not oversold. The domain layer is genuinely well-engineered: idempotent command decisions,
correct no-double-apply discipline, dedup-keyed read-model folds. But the example teaches the
*happy 50%* of an actor system and silently inherits framework defaults for the dangerous 50%:
**zero supervision configuration, zero mailbox configuration, zero writer-identity wiring**. The
worst finding is an interaction bug, not a design gap: the mutable-aggregate pattern combined with
default restart-on-any-Throwable supervision **double-applies the journal on supervised restart**,
which for `InventoryItem` means a transient Doctrine hiccup mid-persist can silently double a SKU's
on-hand stock. Add the permanent-projector-death mode (3 DB failures in 60s → read API frozen until
worker restart), the coroutine-yield double-spawn race in the ref factories, and non-idempotent
Restock behind a 2s ask timeout, and a company copying this into production will get corrupted
inventory numbers under exactly the conditions (DB blips, load spikes, client retries) that
production reliably supplies. The composite delivery story is coherent enough to teach *if* the
undocumented failure modes below are either fixed or added to "Known limitations" — right now the
README documents the races the authors designed around and omits the ones the framework defaults
create.

---

## 1. Delivery + processing guarantees, end to end

Trace of one order: `POST /api/orders` → ask(PlaceOrder, 2s) → OrderActor persists `OrderPlaced` →
engine publishes to ContextBus → thenRun replies 201 → bus tells manager + 2 projectors → manager
spawns/routes to saga → saga persists `FulfillmentStarted` → thenRun tells `ReserveStock` to each
InventoryItem → item persists `StockReserved` → publishes to bus → manager routes to saga → saga
persists `ReservationConfirmed`+`FulfillmentCompleted` → thenRun tells `MarkStockReserved` → order
persists `OrderStockReserved` → bus → projector → `orders_view`.

Per-hop guarantees:

| Hop | Guarantee | Lost on crash | Duplicates |
|---|---|---|---|
| HTTP → OrderActor (ask) | at-most-once processing; journal write is the durable point | command if crash pre-persist | client retry → dedup by `Order::place` status match (`src/Orders/Domain/Order.php:69-83`) — **works** |
| persist → bus publish (`PersistenceEngine.php:219-223`) | at-most-once, in-memory | publish lost if crash between persist and publish (documented) | none |
| bus → subscribers (`src/Platform/Bus/ContextBusActor.php:42-48`) | at-most-once, unbounded mailbox enqueue | everything in-flight on crash; **silently dropped** if subscriber stopped (`packages/nexus-core/src/Actor/LocalActorRef.php:48-55` drops on closed mailbox without even dead-lettering) | none |
| manager → saga (`FulfillmentManagerActor.php:38-44`) | at-most-once; spawn may throw (see §4) | routed event lost if saga spawn/handler fails (restart drops the failing message — `ActorCell.php` restart semantics) | manager may re-deliver nothing; no redelivery exists anywhere |
| saga thenRun → inventory/order tells (`FulfillmentProcessActor.php:88-121`) | at-most-once; thenRun does NOT re-run on replay (documented) | crash between persist and thenRun strands saga in `Reserving` **permanently** | none |
| inventory reply path (StockReserved event → bus → manager → saga) | at-most-once ×3 hops | any hop crash strands saga | none |

**[Important] The composite picture is coherent but its weakest link is unstated: a stranded saga
has no recovery trigger, ever.** The README (`README.md:135-138`) says a passivated saga "resumes
from its journal when the expected events arrive" — true, but if the *triggering* event was lost
(any of 4 at-most-once hops), the expected events never arrive. There is no sweeper, no stuck-saga
metric, no timeout on `Reserving` phase. Blast radius: order stuck in `placed` forever, inventory
holds (if `FulfillmentStarted` side effects half-executed) leak until manual DB surgery. Fix:
until the broker milestone, add a `scheduleRepeatedly` reconciliation sweep (query journal for
sagas in `Reserving` older than N minutes and re-poke them via the factory — replay makes re-poking
idempotent), or at minimum a documented SQL query for operators to find stuck sagas.

**[Important] Exactly-once is faked at exactly one place and it matters: the HTTP retry story.**
`PlaceOrder`/`CancelOrder` retries are genuinely idempotent (aggregate returns current state,
`AggregateBehavior.php:95-102`). But **Restock is not**: `InventoryItem::restock`
(`src/Inventory/Domain/InventoryItem.php:75-78`) unconditionally records `Restocked`; a 2s
`AskTimeoutException` (`RestockHandler.php:44-46`) surfaces as 500 while the command may still be
sitting in the mailbox and *will be processed*; the natural client retry then **doubles stock**.
The README's idempotency section covers only orders. Fix: caller-assigned `restockId` dedup key in
the aggregate (same pattern as reservations), or document loudly that Restock must not be retried.

**[Minor] Read-your-writes is not guaranteed and not documented.** The 201 reply (thenRun,
`PersistenceEngine.php:249-251`) races the projector's DB write (publish at :219-223 happens first,
but the projector consumes asynchronously). A `GET /api/orders/{id}` immediately after a 201 can
404. Fine for a demo; a teaching reference should name it (one sentence in README).

**[Praise]** The durable point is always the journal write, replies are only sent after persist,
and the engine's publish-before-thenRun ordering is documented in three places and actually true
(`PersistenceEngine.php:218-251`, `AggregateBehavior.php:56-57`). That discipline is rarer than it
should be.

---

## 2. Supervision — the dog that didn't bark

`grep withSupervision examples/nexus-fulfillment/src` → **zero hits.** Every actor in the system —
entities, sagas, bus, manager, projectors — runs on the framework default:
`SupervisionStrategy::oneForOne(maxRetries: 3, window: 60s, decider: always Restart)`
(`packages/nexus-core/src/Actor/ActorSystem.php:372`, `SupervisionStrategy.php:38-44`). After 3
failures in 60s: **Stop** (`ActorCell.php:1018-1049`). The spec promised exponentialBackoff
guardians and escalation; escalation isn't even wired in core yet (`ActorCell.php:1005-1012`
`escalateAsStop`). This is the biggest teaching gap. Concrete consequences:

**[Critical] Supervised restart double-applies the journal onto mutated aggregate state —
corrupting inventory counts.** Chain of evidence:
- The engine's setup closure captures `$emptyState` *by value of the object reference*
  (`packages/nexus-persistence/src/EventSourced/PersistenceEngine.php:79-117`).
- The example's event handler **mutates the aggregate in place** and returns the same instance
  (`AggregateBehavior.php:131-136`: `$agg->apply($event); return $agg;`). So after recovery + N
  commands, the captured "$emptyState" object *is* the current state.
- On any handler Throwable (e.g. Doctrine down mid-`persist`, `PersistenceEngine.php:206`), default
  supervision restarts: `ActorCell::restart` resets to `initialBehavior` and **re-runs setup**
  (`ActorCell.php:294-301`). Recovery does `$state = $emptyState` (:94) — the already-mutated
  object — and if **no snapshot exists** (entities with <50 events, i.e. every order, every saga,
  every young SKU — snapshot cadence is `everyN(50)`, `AggregateBehavior.php:149`), replays the
  full journal on top of it.
- `Order.apply` is set-semantics (survives), but `InventoryItem::apply(Restocked)` is
  `$this->onHand += $event->quantity->value` (`InventoryItem.php:138`) → **on-hand doubles per
  restart**, and `FulfillmentProcess::applyReservationConfirmed` appends duplicates to `$confirmed`
  (`FulfillmentProcess.php:173-177`).

Scenario: 3-second Postgres failover; an InventoryItem with 30 events (no snapshot yet) is
processing a Restock; `persist` throws; restart replays 30 events onto live state; on-hand for
that SKU is now 2×. The next command persists events computed from corrupted state, so the
corruption is **journaled** — replay-from-scratch does not heal it. Blast radius: silent, durable,
per-entity inventory corruption triggered by any transient exception. Fix (pick one, in order of
preference): (a) give aggregate actors a supervision decider that maps persistence exceptions to
`Directive::Stop` — this is exactly Akka's `onPersistFailure` stop-by-default rationale — and let
the ref factory respawn a *fresh* behavior (fresh `Order::empty()`) on next message; (b) make the
engine accept an empty-state *factory* closure instead of an instance; (c) make aggregates
copy-on-fold. Option (a) is a 10-line example-level change and also the correct teaching content.

**[Critical] Projector death permanently freezes the read API, silently.** Projectors do one
blocking DB write per event (`OrdersReadModel.php`, `InventoryReadModel.php`). A DB outage longer
than ~3 events → 3 handler throws in 60s → projector **stops** (`ActorCell.php:1035-1044`). The
bus keeps `tell()`ing a closed mailbox, which drops silently (`LocalActorRef.php:52-54`), no dead
letters, no log after the initial `MaxRetriesExceededException`. `GET /api/orders` and
`GET /api/inventory` serve exclusively from these tables — to users, **writes stop appearing** for
the lifetime of the worker process. `ReadinessProbe` checks the DB, not projector liveness. Worse:
even a *restart* (before the cap) drops the in-flight event with no journal catch-up — permanent
read-model drift that the promised-but-absent rebuild (M7) is the only cure for. Fix: projectors
need (a) `exponentialBackoff` supervision with effectively-infinite retries (a projector should
never stay dead while the worker lives), and (b) honest README text that the read model can drift
and cannot yet be rebuilt.

**[Important] One poison order can take down the entire fulfillment pipeline.** The manager
(`FulfillmentManagerActor.php:38-44`) calls `factory->of(...)` *inside its handler*; saga spawn
runs recovery synchronously (`ActorSystem.php:386` → `ActorCell::start` → engine setup → DB load).
If one saga's recovery throws deterministically (corrupt journal row, unregistered event type in
`MessageTypes`, `ConcurrentModificationException` from §5), `spawn` throws
`ActorInitializationException` **in the manager**, and each event touching that order burns one of
the manager's 3 restarts. Three deliveries in 60s → the manager stops → **no order in the system
fulfills anymore**, while HTTP keeps returning 201. Fix: try/catch around routing in the manager
(log + dead-letter the event; a router must never die because a routee is sick) — this is the
classic Erlang lesson the example should teach explicitly.

**[Important] Restart semantics vs event-sourced recovery are never discussed.** Default restart
drops the failing message (at-most-once), clears the stash, and — per the double-apply bug — isn't
even safe here. A teaching reference for event-sourced actors must state the rule: *persistent
actors should stop on persistence failure and be respawned on demand; only side-effect-free
failures are restart-safe*. Currently the example implies (by omission) that defaults are fine.

---

## 3. Mailboxes + backpressure

`grep withMailbox examples/.../src` → **zero hits.** Every mailbox is unbounded (`Props.php:186`).
The spec promised bounded mailboxes + overflow strategy + 429s; none was delivered, and unlike the
bus races, this gap is not in "Known limitations".

**[Important] The ContextBus + projector chain has no backpressure and the slow consumer is a
database.** Fan-out is 1 bus actor → 3 subscribers, each receiving *every* event (subscribers
filter by instanceof; no topic routing). The bus itself is cheap (tell loop,
`ContextBusActor.php:42-48`), so it won't be the bottleneck — the projectors will: one synchronous
pooled-EM write per event, serialized per projector actor, competing for an 8-EM pool
(`DoctrineKit.php:73`). Under a placement burst, projector mailboxes grow without bound → memory
growth + unbounded staleness of GET-after-POST. Nothing measures projector lag. Blast radius:
OOM-kill of the worker under sustained load is the failure mode operators will actually see. Fix:
bounded mailboxes on projectors with `Backpressure` overflow (the bus should feel the push-back),
plus a lag gauge; at the HTTP edge, bounded entity mailboxes + mapping `EnqueueResult::Backpressured`
to 429 — the seam already exists in core (`BackpressureCapable`), which makes its non-use here
conspicuous.

**[Minor] Per-SKU hot spot degrades via latency, not fairness.** A hot SKU serializes
`ReserveStock` from all sagas through one actor with one pooled write per command — that's the
correct actor-model answer, and available() math stays consistent. But with 2s asks (restock) and
unbounded queues, saturation shows up as timeout-500s on restock plus growing saga latency, with no
shed point. Bounded mailbox + `ThrowException`/429 for the HTTP-facing path would give an honest
overload signal.

**[Minor] Bus ordering is FIFO per subscriber, but the example's correctness quietly depends on
it.** The saga relies on `StockReserved` events arriving via bus while `ReserveStock` commands go
*direct* (`FulfillmentProcessActor.php:93-95`) — two unordered channels, which is exactly the root
of the documented compensation sub-race. The comment in the README covers the leak; the general
lesson (never mix a broadcast channel and a direct channel and assume relative order) deserves a
sentence in the tutorial.

**[Praise]** `Subscribe` with no unsubscribe/dedup would be a leak trap in a bigger app, but here
subscription happens exactly 3× per worker at boot (`App.php:87-131`) — proportionate.

---

## 4. Passivation + identity

**[Important] The factory TOCTOU is worse than documented: it's a double-spawn, not just an
exception.** Recovery runs synchronously *inside* `spawn` (`ActorSystem.php:366-392` →
`ActorCell::start` → engine setup → pooled Doctrine I/O), and on Swoole that I/O **yields the
coroutine**. Sequence: request A calls `OrderRefFactory::of` (`OrderRefFactory.php:49-68`), misses
cache, enters `spawn`, suspends on the snapshot query — `$this->children[$name]` is *not yet set*
(`ActorSystem.php:171-173` registers only after `createActorCell` returns) — request B calls
`of()`, misses cache *and* the children map, spawns a **second live actor with the same name and
PersistenceId**; the second registration overwrites the first in the children map. Both recovered
to the same sequenceNr; the first persist by the loser throws `ConcurrentModificationException`
(`packages/nexus-persistence-doctrine/src/DoctrineEventStore.php:47-56`) → default restart → §2's
double-apply. Trigger: a user double-clicking "place order", or the saga's `MarkStockReserved`
racing an HTTP `CancelOrder` on the same order. Fix: `ActorSystem::spawn` needs a per-name
in-progress guard (framework), and until then the factories should serialize spawn per name
(single-flight map) — worth showing in the example since every copy-paste consumer will hit this.

**[Important] Passivation drops queued messages silently.** `ReceiveTimeout` → `Behavior::stopped()`
(`AggregateBehavior.php:141-147`) → `initiateStop` closes the mailbox immediately
(`ActorCell.php:246`); anything enqueued behind the timeout signal is gone, and subsequent `tell`s
to the cached ref drop without dead-lettering (`LocalActorRef.php:48-55`). A saga side-effect
(`ReserveStock`) landing in exactly this window is lost → stranded saga (§1) with *none* of the
documented causes. The 300s window (`App.php:76`) makes it rare, not impossible; production copies
will shrink that window and hit it. Fix: core should dead-letter on close (visibility), and the
canonical entity-passivation pattern (Akka's) is *parent-mediated*: passivate via a
`Passivate` message to the spawner which then buffers incoming messages — worth an explicit
"passivation is not free" README note now, proper fix later.

**[Minor] Spawn-on-any-event saga manager is adequately guarded — by luck of ID validation.**
`OrderId` is ULID-validated, `TenantId` `[a-z0-9-]{1,64}`, `Sku` `[A-Z0-9-]{3,32}` (no `.` or `|`
possible), so the `order-{tenant}.{orderId}` / `item-{tenant}.{sku}` / `process-...` names cannot
collide across contexts or via separator injection — good. But events on the bus are trusted
blindly: the manager spawns a saga (journal replay, DB queries, journal rows) for any
`StockReserved`-shaped event. Today all publishers are internal; the day someone wires the broker
in, this becomes spawn-amplification from untrusted input. A tenant can already create unbounded
actors within a 300s window at the rate the HTTP layer accepts orders — no per-tenant entity cap.
Acceptable for a demo; name it in the scaling section.

**[Praise]** actor-name → PersistenceId mapping is consistent (`order-t.o` ↔ `Order|t|o` etc.), and
`ActorSystem::spawn`'s prune-dead-children semantics make the factories' `isAlive()` check genuinely
belt-and-suspenders as commented.

---

## 5. Single-writer + scaling honesty

**[Critical] The single-writer story is unwired: writerId churns per incarnation and the replay
filter is Off.** The example never calls `withWriterId` or `withReplayFilter` (grep: zero hits).
Consequences:
- Every behavior construction generates a *fresh* ULID (`EventSourcedBehavior.php:81` default) —
  every passivation/respawn cycle stamps a new writer. The journal for any long-lived entity is a
  writer-id patchwork *by design of the example*, meaning writer identity carries zero signal.
- The engine's actual default filter is `ReplayFilter::off()` (`PersistenceEngine.php:76`) — no
  conflict detection at replay. **Framework doc bug:** `EventSourcedBehavior.php:183` claims
  "`ReplayFilter::fail()` (the default)" — false; fix the docblock before someone relies on it.
- Latent data-destruction trap: if a copy-paste consumer "turns on" the advertised safety with
  `ReplayFilter::fail()`, every existing entity that ever passivated fails recovery; with
  `RepairByDiscardOld` it **discards all events before the last respawn**. The example's writer
  churn makes the framework's repair modes actively dangerous.
- Fix: `->withWriterId($system->writerId())` in `AggregateBehavior` (per CLAUDE.md that accessor
  exists) + `ReplayFilter::warn()` — 2 lines, and it's the teaching moment the compose comment
  gestures at.

**[Important] At FULFILLMENT_WORKERS=2 the system corrupts rather than degrades, and the README
doesn't say so.** The only warning is a comment in `compose.yaml:28-31`; the README's "Known
limitations" (`README.md:141-161`) is silent. At 2 workers: independent ActorSystems per process;
HTTP load-balances, so the same order/SKU spawns in both workers; both persist → the loser gets
`ConcurrentModificationException` → nothing catches it anywhere in the example → default restart
→ §2 double-apply corruption → after 3, entity stops and HTTP asks time out. Additionally each
worker has its own ContextBus, so worker A's events never reach worker B's manager/projectors —
sagas and read models fragment even before the write conflict. The documented path (hash-ring /
worker-pool per spec) matches what `nexus-worker-pool` provides, but the code as written assumes
process-local everything (`App.php` builds bus/factories per worker with no directory), so the
migration is a rewrite of the wiring, not a config flip. Fix: (a) copy the compose comment into
README Known limitations verbatim with the corruption consequence spelled out; (b) handle
`ConcurrentModificationException` explicitly (Stop, not restart); (c) state that scaling out is a
worker-pool/cluster milestone, not `FULFILLMENT_WORKERS=2`.

---

## 6. Persistence operations

**[Important] There is no journal-rebuild path and the EventStore API structurally blocks M7.**
`EventStore` (`packages/nexus-persistence/src/Event/EventStore.php`) exposes only
per-PersistenceId `load` — no global feed, no tags, no offset-ordered `currentEventsByTag`
equivalent. A projection rebuild would have to `SELECT DISTINCT persistence_id` + N×load, with no
cross-entity ordering guarantee (per-entity order suffices for these projectors, so it's workable —
but nothing in the API supports resumable offsets for live catch-up). This is the one place M7 is
*structurally* blocked, not just unimplemented. Flag it now so the broker milestone adds a global
sequence/offset column rather than retrofitting one.

**[Minor] Replay cost is actually fine; storage growth is the real curve.** Snapshot `everyN(50)`
(`AggregateBehavior.php:149`) bounds recovery to snapshot + ≤49 events even for a SKU with years of
`Restocked` history — good. But `RetentionPolicy::snapshotAndEvents(3, deleteEventsTo: false)`
(`AggregateBehavior.php:140`) keeps the full journal forever (correct: it's the source of truth and
the only rebuild input) — yet nothing mentions archiving, table partitioning, or the
`inventory_levels.reservations` JSON column growth for high-churn SKUs. One README paragraph on
journal growth expectations would prevent the "why is event_journal 200GB" ticket.

**[Minor] Pooled-store WeakMap is sound; the cache key is the risk to watch.**
`PooledDoctrineEventStore` (`src/Platform/Persistence/PooledDoctrineEventStore.php:37-48`) caches a
wrapper per EM in a `WeakMap` — entries drop when the pool destroys EMs, and `clearOnReturn`
(`EntityManagerPool.php:225-226`) keeps identity maps from leaking, *if enabled*. Two checks for
copy-paste consumers: confirm `EmPoolConfig(max: 8, minIdle: 1)` defaults `clearOnReturn` to true
(if not, every persisted `EventEntry` pins memory for the EM's lifetime), and note that under
`recreateAfter` churn the WeakMap does exactly the right thing. Also `PersistenceEngine.php:73`
takes `Ulid $writerId = new Ulid()` as a *parameter default* — evaluated per call, fine, but it's
the same churn trap as §5.

**[Praise]** Wire-name translation at the store boundary (`event_type` holds
`orders.order_placed.v1`, not FQCN — `PooledDoctrineEventStore.php:53-71`) is the correct upcasting
seam and the snapshot shape-continuity notes (`Order.php:37-40`) show real schema-evolution care.
Rare in examples.

---

## 7. Timeouts + asks

**[Important] Ask timeout ≠ command cancellation; the example never says which commands are safe to
retry.** `ask` (`LocalActorRef.php:78-99`) enqueues and arms a 2s failure timer; on timeout the
HTTP handler throws `AskTimeoutException` → generic Throwable handler → 500 (`App.php:175-189`).
The command **stays in the mailbox and executes later**. Retry matrix as-written: PlaceOrder ✔
(status dedup), CancelOrder ✔ (idempotent), **Restock ✘ (doubles stock, §1)**. Under saturation the
2s budget also silently includes cold-entity recovery (snapshot + events + possibly pool-wait on 8
EMs) — a cold ask under load times out even when healthy. Fixes: per-command idempotency note in
the README table; consider 503+Retry-After for `AskTimeoutException` instead of 500 (it's
overload/slow-path, not a bug); size ask timeout > worst-case recovery or pre-warm via factory.

**[Minor] Ask-timer garbage.** The timeout timer is never cancelled on successful reply
(`LocalActorRef.php:85-87`) — every ask leaves a scheduled closure holding the slot until it fires.
Harmless at demo rates; at production rates it's avoidable scheduler pressure. Framework fix, but
the example is where someone will profile it.

**[Praise]** Handlers use `ask(...)->await()` inside Swoole request coroutines — the correct
pattern (blocks the coroutine, not the loop) — and tests correctly drive asks through
`scheduleOnce` on the Fiber runtime instead. The example models both idioms properly.

---

## 8. The M4 timer question (PickTask deadlines)

Do **not** build PickTask deadlines on `$ctx->scheduleOnce`. Three ways it dies with current core:
(1) passivation (`ReceiveTimeout` → stop, `AggregateBehavior.php:141-147`) cancels all timers
(`ActorCell.php:222-236`) — a 30-minute pick deadline cannot survive a 300s passivation window;
(2) supervised restart cancels timers (`ActorCell.php:269-279`); (3) process restart loses
everything in-memory. A deadline that silently vanishes is worse than no deadline.

Recommended pattern (persist-deadline + external wake), in order:
1. **Persist the deadline as event data** (`PickTaskAssigned{deadline}`) so it's part of saga state
   after any replay. Check-on-wake: every command handler first checks `clock->now() > deadline`
   and emits `PickTaskExpired` if so — expiry is then correct *whenever* the actor happens to wake,
   regardless of timers.
2. **External wake source**: a per-worker singleton sweeper actor using `scheduleRepeatedly`
   (coarse tick, e.g. 10s) over a *deadlines read model* (small projection: persistenceId, deadline,
   phase — folded from the bus like the other projectors), telling due entities via the ref factory.
   Replay + the phase guard makes the poke idempotent; a missed tick only delays, never corrupts.
   This survives passivation (factory respawn), restart, and process death (read model is durable).
3. Do **not** re-arm timers inside `Behavior::setup` as the sole mechanism: the engine has no
   post-recovery hook exposing recovered state to the wrapper (`AggregateBehavior`'s setup at
   :153-157 runs *before* recovery resolves the inner setup), so the example can't even see the
   deadline at wake time without a framework addition. If the framework later grows an
   Akka-style `onRecoveryCompleted(state)` / timers-with-snapshot facility, re-arming becomes a
   latency optimization *on top of* the sweeper — never a replacement, because a passivated actor
   has no timers to re-arm.

This is the same conclusion Akka reached (Persistence + external `Timers` don't mix; use
check-on-wake + a scheduler/read-side sweep, or Akka Projections-style deferred effects).

---

## Top-5 prioritized recommendations

1. **Stop-on-persistence-failure supervision for all aggregate/saga actors** (fixes the
   restart-double-apply corruption, the ConcurrentModification restart loop, and teaches the single
   most important event-sourcing supervision rule). `Props->withSupervision(oneForOne(decider:
   persistence exceptions → Stop))` in the ref factories + let spawn-on-demand respawn fresh.
   ~15 lines, Critical.
2. **Wire writer identity honestly**: `withWriterId($system->writerId())` +
   `ReplayFilter::warn()` in `AggregateBehavior`; catch `ConcurrentModificationException` as Stop;
   fix the `EventSourcedBehavior.php:183` doc lie ("fail() is the default" — it's off()).
3. **Make projectors immortal and the manager poison-proof**: exponentialBackoff with unbounded
   retries on both projectors; try/catch around `factory->of(...)->tell(...)` in
   `FulfillmentManagerActor`. Add "a stopped projector freezes reads until worker restart" to
   Known limitations until then.
4. **Promote the deployment truths into README Known limitations**: FULFILLMENT_WORKERS must be 1
   (with the corruption consequence at 2), Restock is not retry-safe, read model is eventually
   consistent, passivation can drop in-flight messages, stranded-saga detection query for operators.
5. **Deliver the promised backpressure edge before anyone benchmarks this**: bounded mailboxes on
   projectors + entities, `Backpressured` → 429/503 at the HTTP handlers (the `BackpressureCapable`
   seam already exists in core), and a projector-lag gauge.

---

## What's genuinely well-engineered

- **Domain-level idempotency as the first line of defense**: every aggregate decision is a no-op
  or state-echo on duplicates (`Order.php:69-109`, `InventoryItem.php:80-114`,
  `FulfillmentProcess.php:74-121`) — the at-most-once transport is survivable *because* the domain
  was built for redelivery it doesn't even get yet. That's the right order to build in.
- **The compensation release-set union** (confirmed ∪ pending, `FulfillmentProcessActor.php:101-118`)
  with the rejection-races-ahead rationale documented inline — a subtle saga bug class most
  production systems discover in postmortems, pre-solved and explained.
- **No-double-apply discipline** (record vs apply separation) stated identically in all three
  aggregates with the failure mode it prevents — ironic given §2's finding, but the discipline
  itself is correct and correctly documented.
- **ID value objects that make name-injection structurally impossible** (`Sku.php:18`,
  `TenantId.php:19`, ULID OrderId) — the actor-name/PersistenceId scheme is collision-free by
  construction, not by convention.
- **The wire-name registry + snapshot shape-continuity** (`PooledDoctrineEventStore` translation,
  `inventory.item_state.v1` mapped onto the refactored class) — real schema-evolution engineering.
- **Honest limitation documentation culture**: the three documented races are accurately described,
  with the *residual* sub-race after the mitigation explicitly called out (`README.md:154-161`).
  The gaps this review found are omissions, not misrepresentations — the fix is more of the same
  honesty, applied to the framework defaults the example inherited.
