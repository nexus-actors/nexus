# ClusterNode Actorization — Plan 2: Transport SPI + Pumps

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extract the transport edge behind the SPI — carriers under `Transport/`, pump-layer classes owning link bookkeeping, `PeerAuthenticator` seam, URI-tolerant addressing — with **zero behavior change**; `ClusterNode` keeps driving the edge through injected closures.

**Architecture:** Namespace moves + two extracted plain classes (`InboundLinkAcceptor`, `PeerConnectionPool`) that absorb ClusterNode's transport bookkeeping while `handleLinkFrame` (the protocol state machine, Plan 3's actor target) stays in ClusterNode and is injected into the pumps as an opaque frame-sink closure — exactly the shape the current code already has via closures. Spec §3.4; roadmap Plan 2.

**Tech Stack:** PHP 8.5 (Docker only), PHPUnit 13, Psalm level 1 zero-suppressions, Deptrac 4.6, GrumPHP per commit.

## Global Constraints

- All commands via Docker (`docker compose exec -T php …`); GrumPHP (cs-fixer, phpcs, psalm, phpunit) green on EVERY commit.
- **Zero behavior change.** Every hazard below is a behavior contract; regressing any is a plan failure.
- **Wire strings stay bare `host:port` byte-identical**: `NodeEndpoint::__toString` must NOT change — the handshake HMAC signs the literal advertise string; `(string)$endpoint` is the identity key of `$outboundConns`, `MeshOutboundSink`, and `LoopbackHub::$listeners`; gossip/ack/handshake payload encodings are frozen.
- **C3 (phi ingress stamp) + throttle stay pre-mailbox**: `observedAt` stamped at frame-parse (`ClusterNode.php:748`), `PeerLivenessObserved` clock at `:716`, `LivenessThrottle::shouldObserve` gate at `:715` — none of these move behind a mailbox in this plan.
- Preserved-verbatim semantics: preamble-then-flush order; `intentionallyClosed` set BEFORE `close()`; re-handshake slot replacement WITHOUT closing prior link + object-identity-guarded removal; lazy outbound conns are SEND-ONLY (no onFrame); Message-sync/control-async `routeSend` split; Slowloris deadline cancel on accept AND close; tombstone-vs-slot ordering; maxInboundLinks gate before any wiring; backoff reset-after-success; drop-newest queue cap 100.
- The soak gate judges **per-node verdicts** (aggregate line can spuriously FAIL on the pre-existing teardown hang).
- Branch `refactor/cluster-node-actorization`; controller pushes after each task; never commit the pre-existing `.gitignore` working-tree edit.

---

### Task 1: NodeEndpoint URI surface (additive)

**Files:**
- Modify: `packages/nexus-cluster-tcp/src/NodeEndpoint.php`
- Test: `packages/nexus-cluster-tcp/tests/Unit/NodeEndpointTest.php` (extend)

**Interfaces:**
- Produces: `NodeEndpoint::fromUri(string $uri): self` (accepts `tcp://host:port`; rejects other schemes with `InvalidArgumentException`), `NodeEndpoint::toUri(): string` (returns `tcp://{host}:{port}`), and `fromString()` becomes scheme-tolerant (a leading `tcp://` is stripped, then the existing last-colon split runs unchanged). `__toString()` is UNTOUCHED.
- Consumes: existing `Host::of()`, `Port::of()`.

- [ ] **Step 1: Write the failing tests** (append to the existing `NodeEndpointTest`, matching its style):

```php
    #[Test]
    public function fromUriAcceptsTcpScheme(): void
    {
        $endpoint = NodeEndpoint::fromUri('tcp://10.0.0.1:7355');

        self::assertSame('10.0.0.1:7355', (string) $endpoint);
        self::assertSame('tcp://10.0.0.1:7355', $endpoint->toUri());
    }

    #[Test]
    public function fromUriRejectsUnknownScheme(): void
    {
        $this->expectException(InvalidArgumentException::class);

        NodeEndpoint::fromUri('http://10.0.0.1:7355');
    }

    #[Test]
    public function fromStringToleratesTcpScheme(): void
    {
        self::assertSame('10.0.0.1:7355', (string) NodeEndpoint::fromString('tcp://10.0.0.1:7355'));
    }

    #[Test]
    public function toStringStaysBareHostPort(): void
    {
        self::assertSame('example.org:9000', (string) NodeEndpoint::fromString('example.org:9000'));
    }
```

- [ ] **Step 2: Run to verify failure** — `docker compose exec -T php vendor/bin/phpunit packages/nexus-cluster-tcp/tests/Unit/NodeEndpointTest.php` → FAIL (`fromUri` undefined).

- [ ] **Step 3: Implement** in `NodeEndpoint.php` — add a private const `SCHEME = 'tcp://'`; in `fromString()`, first line: strip the prefix if present (`str_starts_with($hostPort, self::SCHEME)` → `substr`), then the EXISTING last-colon logic verbatim (do NOT use `parse_url` — it misclassifies scheme-less host:port). Add:

```php
    /**
     * Parses a URI-style endpoint ("tcp://host:port"). Only the tcp scheme is
     * accepted; the canonical textual form (__toString, wire payloads, map
     * keys) remains bare "host:port" — the URI form is config/display surface
     * for the transport SPI (spec §3.4.1).
     *
     * @throws InvalidArgumentException
     */
    public static function fromUri(string $uri): self
    {
        if (!str_starts_with($uri, self::SCHEME)) {
            throw new InvalidArgumentException("Unsupported endpoint URI scheme: {$uri}");
        }

        return self::fromString($uri);
    }

    public function toUri(): string
    {
        return self::SCHEME . (string) $this;
    }
```

(Add `use function str_starts_with;`/`substr` imports per repo convention; docblock the scheme-tolerance on `fromString`.)

- [ ] **Step 4: Run to verify pass** (same command) + run the four wire-critical suites the string form feeds: `docker compose exec -T php vendor/bin/phpunit packages/nexus-cluster-tcp/tests/Unit/Payload packages/nexus-cluster-tcp/tests/Unit/Membership` → all green.

- [ ] **Step 5: Commit** — `feat(cluster-tcp): add URI surface to NodeEndpoint, canonical form unchanged`

---

### Task 2: `PeerAuthenticator` seam

**Files:**
- Create: `packages/nexus-cluster-tcp/src/Membership/PeerAuthenticator.php`
- Modify: `packages/nexus-cluster-tcp/src/Membership/HandshakeAuthenticator.php` (implements the interface), `packages/nexus-cluster-tcp/src/ClusterNode.php` (type against the interface)
- Test: `packages/nexus-cluster-tcp/tests/Unit/Membership/HandshakeAuthenticatorTest.php` (one added conformance test)

**Interfaces:**
- Produces: `interface PeerAuthenticator { public function sign(Handshake $handshake): Handshake; public function verify(Handshake $handshake, int $nowUnix): bool; }` — the admission capability of the transport SPI (spec §3.4.2). Lives in `Membership/` (core), NOT `Transport/`: the HMAC impl is core and core must not depend on Transport (our own Deptrac rule).
- Consumes: existing `HandshakeAuthenticator::sign/verify` signatures (verified: `sign(Handshake): Handshake` at :88, `verify(Handshake, int $nowUnix): bool` at :110) — the interface extracts them verbatim.

- [ ] **Step 1: Failing test** — add to `HandshakeAuthenticatorTest`: `self::assertInstanceOf(PeerAuthenticator::class, new HandshakeAuthenticator('secret'));` → run → FAIL (interface missing).
- [ ] **Step 2: Create the interface** (docblock: stateful implementations are confined to the single admission point — exactly ONE instance per node; nonce-replay correctness depends on it). `HandshakeAuthenticator` gets `implements PeerAuthenticator` + `#[Override]` on both methods (`use Override;`).
- [ ] **Step 3: Retype ClusterNode** — the constructor property `private readonly ?HandshakeAuthenticator $authenticator` (ClusterNode.php:187) and any local type references become `?PeerAuthenticator`. Boot still constructs `new HandshakeAuthenticator(...)` (:332-334). The sign site (:1271-1273) and verify site (:978) are interface calls already — no body changes. Preserve the sign-uses-injected-clock / verify-uses-`time()` asymmetry exactly.
- [ ] **Step 4: Run** `docker compose exec -T php vendor/bin/phpunit packages/nexus-cluster-tcp/tests/Unit/Membership` + `docker compose exec -T php vendor/bin/psalm --no-progress` → green. Delete the psalm.xml `PossiblyUnusedMethod`/`UnusedClass` entries ONLY if this task makes any exempted seam class consumed (it does not — leave psalm.xml alone).
- [ ] **Step 5: Commit** — `feat(cluster-tcp): extract PeerAuthenticator admission seam (spec §3.4.2)`

---

### Task 3: Carriers move under `Transport/` (mechanical namespace migration)

**Files (move map — `git mv` + namespace/import rewrite):**

| From (`packages/nexus-cluster-tcp/src/`) | To |
|---|---|
| `Frame.php`, `FrameType.php` | `Protocol/` (`…\Cluster\Tcp\Protocol`) — wire model beside `WireFormat` |
| `FrameCodec.php` | `Transport/` — framing is a transport concern (spec §3.4.4) |
| `MeshTransport.php`, `PeerLink.php`, `LinkState.php`, `PeerConnection.php` | `Transport/` |
| `Swoole/SwooleMeshTransport.php`, `Swoole/SwoolePeerLink.php` | `Transport/Tcp/` (namespace `…\Transport\Tcp`, class names unchanged) |
| `Loopback/LoopbackHub.php`, `LoopbackMeshTransport.php`, `LoopbackPeerLink.php` | `Transport/Loopback/` |
| `Messaging/MeshOutboundSink.php` | `Transport/` — discovered during execution: it constructs `PeerConnection`/`MeshTransport` (a Core→Transport edge post-move). It implements the core `OutboundSink` interface FROM the transport layer (allowed direction), has no src consumers (test-only twin of the anonymous sink; dies in Plan 5), and its REL-009 test coverage rides along via import updates. |

NOT moved: `NodeEndpoint`, `DeliveryOutcome` (root — shared carriers consumed by core; the two-enum unification is Plan 5), `EndpointResolver`/`MapEndpointResolver`/`MutableEndpointRegistry` (core directory), `TlsConfig` (consumed by topology config — root), `ClusterTopology`, `NodeEndpoint`-census test files (imports updated only).

**Also modify (config/guards that encode the old paths):**
- `deptrac.yaml` — `ClusterTcpTransport` collector regex becomes `packages/nexus-cluster-tcp/src/Transport/.*`; `ClusterTcpCore`'s `must_not` mirrors it (plus the unchanged `ClusterNode\.php` entry).
- `bin/verify-cluster-boundary.php` — fixture's import becomes `use Monadial\Nexus\Cluster\Tcp\Transport\Loopback\LoopbackHub;`; Gate-1 expected substring becomes `must not depend on Monadial\Nexus\Cluster\Tcp\Transport`.
- `psalm.xml` — no changes (exempted seam files didn't move).

**Procedure (order matters to keep every commit green — this is ONE commit):**

- [ ] **Step 1:** `git mv` per the move map; rewrite `namespace` declarations; sweep ALL imports repo-wide (`packages/`, `tests/`, `examples/` if any, and the four recovered perf scripts under `tests/Performance/` which construct transports/endpoints — the census says `thread_mesh_node.php`, `roundtrip_node.php`, `cluster_tcp_soak.php`, `cluster_tcp_bench_worker.php` construct `NodeEndpoint` (not moved) but check them for moved-class imports too). Use `grep -rl 'Cluster\\Tcp\\(Swoole|Loopback)\\|Tcp\\Frame\b' …` sweeps rather than memory.
- [ ] **Step 2:** Update `deptrac.yaml` + `bin/verify-cluster-boundary.php` per above.
- [ ] **Step 3:** Full verification loop: `docker compose exec -T php vendor/bin/psalm --no-progress` (0 errors), `php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/deptrac analyse --no-progress` (0 violations), `php bin/verify-cluster-boundary.php` (exit 0), `vendor/bin/phpunit` unit suite, `make test-fiber && make test-swoole && make test-cluster` (all green), `php -l` the four perf scripts.
- [ ] **Step 4: Commit** — `refactor(cluster-tcp): move carriers under Transport/, wire model under Protocol/`
  (body: the move map + "zero behavior change; deptrac boundary regexes + fixture guard updated".)

---

### Task 4: Pump extraction — `InboundLinkAcceptor` + `PeerConnectionPool`

**Files:**
- Create: `packages/nexus-cluster-tcp/src/Transport/InboundLinkAcceptor.php`
- Create: `packages/nexus-cluster-tcp/src/Transport/PeerConnectionPool.php`
- Modify: `packages/nexus-cluster-tcp/src/ClusterNode.php` (delegate; net-negative lines)
- Test: `packages/nexus-cluster-tcp/tests/Unit/Transport/InboundLinkAcceptorTest.php`, `.../PeerConnectionPoolTest.php`

**Interfaces:**

`InboundLinkAcceptor` — absorbs `wireInboundLink`'s transport bookkeeping (ClusterNode.php:813–901) verbatim:
```php
final class InboundLinkAcceptor
{
    /**
     * @param Closure(Frame, LinkState, string): void $frameSink   ClusterNode::handleLinkFrame partial (router+accepted-callback pre-bound)
     * @param Closure(LinkState, PeerLink): void      $onLinkClosed ClusterNode's close bookkeeping bundle
     */
    public function __construct(
        private readonly Runtime $runtime,
        private readonly int $maxInboundLinks,
        private readonly Duration $handshakeTimeout,
        private readonly Closure $frameSink,
        private readonly Closure $onLinkClosed,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function accept(PeerLink $link): void;   // capacity gate → close; inboundLinks set; LinkState; Slowloris deadline; onFrame → frameSink; onClose → deadline cancel + inboundLinks remove + onLinkClosed
    public function liveInboundCount(): int;        // introspection (replaces count($this->inboundLinks))
}
```
The Slowloris `Cancellable` is stored per-link inside the acceptor and cancelled on BOTH accept-identification and close; the accept-side cancel is exposed by passing the cancellable into the `frameSink` path — concretely: the acceptor wraps `$frameSink` so ClusterNode's `$onHandshakeAccepted` closure (slot registration, C2 semantics) STAYS in ClusterNode, but the deadline-cancel half moves into the acceptor (it owns the timer). ClusterNode's accepted-callback shrinks to slot registration only.

`PeerConnectionPool` — absorbs the `$outboundConns` map (dedup keys `(string) NodeEndpoint`, ClusterNode.php:540, :571–583, :910–937, :499–513):
```php
final class PeerConnectionPool
{
    public function __construct(
        private readonly MeshTransport $transport,
        private readonly Runtime $runtime,
        private readonly Duration $initialBackoff,
        private readonly Duration $maxBackoff,
        private readonly Closure $preamble,           // Closure(): Frame — handshakePreamble()
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function dial(NodeEndpoint $endpoint): PeerConnection;      // dedup by (string)$endpoint; construct-or-return (PeerConnection ctor dials immediately — dedup prevents double reconnect loops)
    public function existing(NodeEndpoint $endpoint): ?PeerConnection; // no side effects
    public function evict(NodeEndpoint $endpoint): void;               // close + remove (evictOutbound path — graceful Leave only, caller enforces)
    public function closeAll(): void;                                  // shutdown: close every conn, clear map
    /** @param Closure(PeerConnection): void $fn */
    public function each(Closure $fn): void;                           // shutdown Leave broadcast
    public function count(): int;
}
```
CRITICAL asymmetry preserved: `dial()` does NOT wire onFrame — seed connections get their pump wired by ClusterNode (`dialSeed` calls `$pool->dial($seed)` then `$conn->onFrame(...)` exactly as today at :933–936); lazy sends (`sendByPrefix` :573–583) call `dial()` and wire nothing (send-only, hazard-documented).

**ClusterNode changes:** `$inboundLinks` property deleted (acceptor owns it); `$outboundConns` property deleted (pool owns it); `wireInboundLink` shrinks to the accepted-callback + close-bundle closures handed to the acceptor at boot; `dialSeed`/`sendByPrefix`/`evictOutbound`/`shutdown` delegate to the pool. `$acceptedLinks`, tombstones, `handleLinkFrame`, admission — ALL STAY in ClusterNode (Plan 3 targets). No public API change.

- [ ] **Step 1: Failing unit tests first.** `InboundLinkAcceptorTest` (use `Tests\Support` fakes or loopback links + the core `TestRuntime` from `packages/nexus-core/tests/Support`): capacity gate closes the (maxInboundLinks+1)th link without invoking frameSink; Slowloris deadline fires → unidentified link closed, timer no-op after identification; frames reach frameSink with the right LinkState; close invokes onLinkClosed exactly once and removes from live count. `PeerConnectionPoolTest` (loopback transport + hub): dial dedups by endpoint string; evict closes and removes; closeAll clears; each() visits every live conn; dial-after-evict re-creates.
- [ ] **Step 2:** RED run, then implement both classes by MOVING the existing code blocks (the plan deliberately reuses the current logic verbatim — reviewers will diff the moved blocks against `ClusterNode.php:813-901`, `:910-937`, `:571-583`, `:499-513`).
- [ ] **Step 3:** GREEN run: new unit tests + `make test-cluster` + `make test-swoole` + `make test-fiber`.
- [ ] **Step 4: Soak gate (link-lifecycle change → soak now, not batched):** `make soak-idle` AND `make soak-mesh` — per-node verdicts 16/16 PASS, down=0, no suspicion growth. Record numbers in the report.
- [ ] **Step 5: Commit** — `refactor(cluster-tcp): extract InboundLinkAcceptor and PeerConnectionPool pumps`

---

### Task 5: Plan-2 verification + PR update

- [ ] **Step 1:** Full gates: `make psalm && make phpcs && make cs && make test-unit && make test-fiber && make test-swoole && make test-cluster`; `make cluster-boundary-check`; deptrac 0 violations.
- [ ] **Step 2:** `make bench-saturation` — compare against Plan-1 baseline (978,555 msg/s @K=16): must be ≥90% (spec §8.2). Record.
- [ ] **Step 3:** Report block to `.superpowers/sdd/plan2-verification.md` (gates, soak verdicts from Task 4, saturation delta). No commits unless a gate fails and a fix is needed.

## Self-review notes
- Spec coverage: §3.4.1 addressing → T1; §3.4.2 admission seam → T2; §3.4 packaging + §3.3 layout → T3; §3.2 pumps + C3/C7 preservation → T4; §8.2 gate → T5. Deliberately deferred to Plan 3: actorizing `handleLinkFrame`, admission-behavior split, `RoutingSnapshot`.
- The hazard list in Global Constraints is the reviewer lens for every task.
- Type consistency: `frameSink` closure shape matches `handleLinkFrame(Frame, LinkState, InboxRouter, string, Closure)` partial application; pool method names used consistently in T4's ClusterNode delegation description.
