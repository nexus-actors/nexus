# Receive-Time Heartbeat Timestamping Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Feed the phi failure detector the true socket-receive time of each liveness signal instead of the `MembershipActor`'s processing time, so failure detection measures the network rather than local scheduler contention.

**Architecture:** Stamp an `observedAt` timestamp in the recv coroutine at frame ingress (`ClusterNode::observeLiveness`), carry it on the `PeerLivenessObserved` message, and thread it through `MembershipService::applyLiveness` → `recordLiveness` so it is used **only** at the `$detector->heartbeat()` call. All view/status evolution keeps using the processing-time `$now`. The handshake path (`applyHandshake`) deliberately keeps passing processing-time, out of scope.

**Tech Stack:** PHP 8.5, Swoole coroutines, PHPUnit, Psalm level 1, PHP-CS-Fixer/PHPCS. All commands run in Docker (`docker compose exec`), never on the host.

## Global Constraints

- **Docker only.** Every PHP/Composer/PHPUnit/Psalm command runs via `docker compose exec php ...` (or `php-swoole`). No host PHP.
- **Never add `Co-Authored-By: Claude` to commits.**
- **Never stage `.deptrac.cache`.** Run `git checkout -- .deptrac.cache` before every `git add`.
- **Commits currently unsigned** due to a GPG-agent hang: commit with `--no-gpg-sign`. GrumPHP gates are run manually before committing.
- **Code style:** PER-CS2.0 + Slevomat. Arrays with string keys sorted alphabetically. Blank line before `if`/`for`/`foreach`/`while`/`switch`/`try`. All classes `final`, value objects `readonly`. Trailing commas in multiline. Ordered imports (class, function, const — each alphabetical).
- **Internal message only:** `PeerLivenessObserved` is constructed solely in `ClusterNode`; it has no wire/serialized form, so adding a required constructor field is safe.
- **Acceptance is the soak, not unit-green:** the 16-node distributed mesh-soak at default phi is authoritative for closing the finding (Task 4).

---

### Task 1: Add `observedAt` field to `PeerLivenessObserved`

**Files:**
- Modify: `packages/nexus-cluster-tcp/src/Membership/Message/PeerLivenessObserved.php`
- Test: `packages/nexus-cluster-tcp/tests/Unit/Membership/Message/PeerLivenessObservedTest.php` (create)

**Interfaces:**
- Consumes: `Monadial\Nexus\Cluster\NodeAddress`, `Monadial\Nexus\Cluster\Tcp\NodeEndpoint`.
- Produces: `PeerLivenessObserved::__construct(NodeAddress $peer, ?NodeEndpoint $endpoint = null, DateTimeImmutable $observedAt)` with a public readonly `DateTimeImmutable $observedAt`. Note `$observedAt` is **required** (no default) and comes after the optional `$endpoint`; every construction site passes all three.

- [ ] **Step 1: Write the failing test**

Create `packages/nexus-cluster-tcp/tests/Unit/Membership/Message/PeerLivenessObservedTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Unit\Membership\Message;

use DateTimeImmutable;
use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\Membership\Message\PeerLivenessObserved;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PeerLivenessObserved::class)]
final class PeerLivenessObservedTest extends TestCase
{
    #[Test]
    public function itCarriesTheIngressObservationTimestamp(): void
    {
        $peer = new NodeAddress('production', 'eu', 'payments', 'node-1');
        $endpoint = NodeEndpoint::fromString('10.0.0.1:7355');
        $observedAt = new DateTimeImmutable('2026-07-10 00:00:00.000000');

        $message = new PeerLivenessObserved($peer, $endpoint, $observedAt);

        self::assertSame($peer, $message->peer);
        self::assertSame($endpoint, $message->endpoint);
        self::assertSame($observedAt, $message->observedAt);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose exec -T php vendor/bin/phpunit packages/nexus-cluster-tcp/tests/Unit/Membership/Message/PeerLivenessObservedTest.php`
Expected: FAIL — `PeerLivenessObserved::__construct()` does not accept a third argument / `observedAt` property undefined.

- [ ] **Step 3: Add the field**

Edit `PeerLivenessObserved.php` — add the `DateTimeImmutable` import and the constructor field. Final file body:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Membership\Message;

use DateTimeImmutable;
use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Core\Actor\UntracedMessage;

/**
 * @psalm-api
 *
 * Any inbound frame from a peer (data frame, ping, or pong) that proves the peer
 * is alive. Maps to MembershipService::applyLiveness — feeds the phi detector,
 * adds a newly-seen peer (requires a non-null endpoint), or recovers a Suspect
 * peer to Up. `endpoint` is null when the peer is already known.
 *
 * `observedAt` is the socket-receive time stamped at frame ingress (in the recv
 * coroutine), NOT the time the membership actor processes this message. Feeding
 * the phi detector the ingress time keeps failure detection immune to local
 * scheduler contention under data-plane load.
 */
final readonly class PeerLivenessObserved implements UntracedMessage
{
    public function __construct(
        public NodeAddress $peer,
        public ?NodeEndpoint $endpoint,
        public DateTimeImmutable $observedAt,
    ) {}
}
```

Note: `$endpoint` loses its `= null` default (it now precedes a required arg). This is safe — the two production call sites (Task 3) pass it explicitly, and no other code constructs this message.

- [ ] **Step 4: Run the test to verify it passes**

Run: `docker compose exec -T php vendor/bin/phpunit packages/nexus-cluster-tcp/tests/Unit/Membership/Message/PeerLivenessObservedTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
cd /Users/tomas/Work/Monadial/CodeOSS/nexus
git checkout -- .deptrac.cache 2>/dev/null
git add packages/nexus-cluster-tcp/src/Membership/Message/PeerLivenessObserved.php \
        packages/nexus-cluster-tcp/tests/Unit/Membership/Message/PeerLivenessObservedTest.php
git commit --no-gpg-sign --no-verify -m "feat(cluster-tcp): carry ingress observedAt on PeerLivenessObserved"
```

---

### Task 2: Thread `observedAt` through the failure-detector feed

**Files:**
- Modify: `packages/nexus-cluster-tcp/src/Membership/MembershipService.php` (`recordLiveness` ~line 407, `applyLiveness` ~line 198, `applyHandshake` call site ~line 122)
- Modify: `packages/nexus-cluster-tcp/src/Membership/MembershipActor.php` (`PeerLivenessObserved` branch ~line 174)
- Test: `packages/nexus-cluster-tcp/tests/Unit/Membership/MembershipServiceTest.php` (new test + update existing `applyLiveness` call sites)

**Interfaces:**
- Consumes: `PeerLivenessObserved::$observedAt` (Task 1).
- Produces:
  - `MembershipService::applyLiveness(ClusterView $view, array $suspectSince, int $selfIncarnation, PhiAccrualDetector $detector, NodeAddress $peer, ?NodeEndpoint $endpoint, DateTimeImmutable $observedAt, DateTimeImmutable $now): MembershipTransition` — `$observedAt` inserted immediately before `$now`.
  - `MembershipService::recordLiveness(...)` (private) gains the same `DateTimeImmutable $observedAt` immediately before `$now`; `$detector->heartbeat($key, $observedAt)` uses it; every other line keeps `$now`.

- [ ] **Step 1: Write the failing test**

Add to `packages/nexus-cluster-tcp/tests/Unit/Membership/MembershipServiceTest.php` (place beside the other `applyLiveness` tests). This test proves the detector's last-arrival is set to `observedAt`, not the (much later) processing `$now`:

```php
    #[Test]
    public function livenessFeedsTheDetectorAtObservedTimeNotProcessingTime(): void
    {
        $service = $this->service();
        $t0 = $service->initialState($this->clock->now());

        $peer = new NodeAddress('production', 'eu', 'payments', 'node-9');
        $endpoint = NodeEndpoint::fromString('10.0.0.9:7355');

        // Bytes arrived at observedAt; the membership actor only processed the
        // message 8s later (simulating data-plane scheduler contention).
        $observedAt = new DateTimeImmutable('2026-07-10 00:00:02.000000');
        $processingNow = new DateTimeImmutable('2026-07-10 00:00:10.000000');

        $service->applyLiveness(
            $t0->newView,
            $t0->newSuspectSince,
            $t0->newSelfIncarnation,
            $this->detector,
            $peer,
            $endpoint,
            $observedAt,
            $processingNow,
        );

        // The detector recorded the arrival at observedAt: elapsed-since is 0 AT observedAt.
        self::assertSame(
            0.0,
            $this->detector->millisSinceLastHeartbeat($peer->toPathPrefix(), $observedAt),
        );
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose exec -T php vendor/bin/phpunit --filter=livenessFeedsTheDetectorAtObservedTimeNotProcessingTime packages/nexus-cluster-tcp/tests/Unit/Membership/MembershipServiceTest.php`
Expected: FAIL — `applyLiveness()` does not yet accept 8 arguments (too many arguments / signature mismatch).

- [ ] **Step 3: Add `$observedAt` to `recordLiveness` and use it for the detector feed**

In `MembershipService.php`, change the `recordLiveness` signature and its first two lines. Before:

```php
    private function recordLiveness(
        ClusterView $view,
        array $suspectSince,
        PhiAccrualDetector $detector,
        NodeAddress $peer,
        ?NodeEndpoint $endpoint,
        DateTimeImmutable $now,
    ): array {
        $key = $peer->toPathPrefix();
        $detector->heartbeat($key, $now);
```

After:

```php
    private function recordLiveness(
        ClusterView $view,
        array $suspectSince,
        PhiAccrualDetector $detector,
        NodeAddress $peer,
        ?NodeEndpoint $endpoint,
        DateTimeImmutable $observedAt,
        DateTimeImmutable $now,
    ): array {
        $key = $peer->toPathPrefix();
        $detector->heartbeat($key, $observedAt);
```

Everything else in `recordLiveness` keeps using `$now` (view status, recovery, `MemberRecord` timestamp).

- [ ] **Step 4: Add `$observedAt` to `applyLiveness` and forward it**

In `MembershipService.php`, `applyLiveness`. Before:

```php
    public function applyLiveness(
        ClusterView $view,
        array $suspectSince,
        int $selfIncarnation,
        PhiAccrualDetector $detector,
        NodeAddress $peer,
        ?NodeEndpoint $endpoint,
        DateTimeImmutable $now,
    ): MembershipTransition {
        [$newView, $newSuspectSince, $events] = $this->recordLiveness(
            $view,
            $suspectSince,
            $detector,
            $peer,
            $endpoint,
            $now,
        );
```

After:

```php
    public function applyLiveness(
        ClusterView $view,
        array $suspectSince,
        int $selfIncarnation,
        PhiAccrualDetector $detector,
        NodeAddress $peer,
        ?NodeEndpoint $endpoint,
        DateTimeImmutable $observedAt,
        DateTimeImmutable $now,
    ): MembershipTransition {
        [$newView, $newSuspectSince, $events] = $this->recordLiveness(
            $view,
            $suspectSince,
            $detector,
            $peer,
            $endpoint,
            $observedAt,
            $now,
        );
```

- [ ] **Step 5: Keep the handshake path on processing-time**

In `MembershipService.php`, `applyHandshake`'s `recordLiveness` call (~line 122) must pass `$now` for the new `$observedAt` slot — the handshake is a one-time join event and stays on processing-time per the design. Before:

```php
        [$view1, $suspectSince1, $events1] = $this->recordLiveness(
            $view,
            $suspectSince,
            $detector,
            $peer,
            $endpoint,
            $now,
        );
```

After:

```php
        [$view1, $suspectSince1, $events1] = $this->recordLiveness(
            $view,
            $suspectSince,
            $detector,
            $peer,
            $endpoint,
            $now,
            $now,
        );
```

- [ ] **Step 6: Pass `observedAt` from the membership actor**

In `MembershipActor.php`, the `PeerLivenessObserved` branch (~line 174). Before:

```php
            $message instanceof PeerLivenessObserved => $this->apply($this->service->applyLiveness(
                $state->view,
                $state->suspectSince,
                $state->selfIncarnation,
                $this->detector,
                $message->peer,
                $message->endpoint,
                $now,
            )),
```

After:

```php
            $message instanceof PeerLivenessObserved => $this->apply($this->service->applyLiveness(
                $state->view,
                $state->suspectSince,
                $state->selfIncarnation,
                $this->detector,
                $message->peer,
                $message->endpoint,
                $message->observedAt,
                $now,
            )),
```

- [ ] **Step 7: Update every existing `applyLiveness` call site in the test**

There are ~10 existing `applyLiveness(...)` calls in `MembershipServiceTest.php` (lines ~192, 277, 392, 422, 450, 462, 495, 512, 533, 574 — verify with grep). None care about the ingress/processing distinction, so pass the **same timestamp they already pass as the final `$now`** for the new `$observedAt` slot, preserving their behavior. Each call currently ends:

```php
        $service->applyLiveness(
            ...,
            $peer,
            $endpoint,
            $someNow,          // final arg today
        );
```

Insert a duplicate of that final timestamp as the new second-to-last argument:

```php
        $service->applyLiveness(
            ...,
            $peer,
            $endpoint,
            $someNow,          // observedAt (new)
            $someNow,          // now
        );
```

Find them all with:

```bash
docker compose exec -T php grep -n "applyLiveness(" packages/nexus-cluster-tcp/tests/Unit/Membership/MembershipServiceTest.php
```

Apply the transformation to each. Where the final arg is `$this->clock->now()`, the new line is `$this->clock->now(),` as well; where it is a named `$t`/`$now` variable, duplicate that variable.

- [ ] **Step 8: Run the cluster-tcp unit suite to verify green**

Run: `docker compose exec -T php vendor/bin/phpunit packages/nexus-cluster-tcp/tests/Unit`
Expected: PASS — including the new `livenessFeedsTheDetectorAtObservedTimeNotProcessingTime`. (Baseline was 240 unit tests; this adds one in this file plus Task 1's message test.)

- [ ] **Step 9: Psalm + style gates**

```bash
docker compose exec -T php vendor/bin/psalm --no-cache packages/nexus-cluster-tcp/src/Membership/MembershipService.php packages/nexus-cluster-tcp/src/Membership/MembershipActor.php
docker compose exec -T php vendor/bin/phpcbf packages/nexus-cluster-tcp/src/Membership packages/nexus-cluster-tcp/tests/Unit/Membership || true
docker compose exec -T php vendor/bin/php-cs-fixer fix packages/nexus-cluster-tcp/src/Membership
docker compose exec -T php vendor/bin/php-cs-fixer fix packages/nexus-cluster-tcp/tests/Unit/Membership
```

Expected: Psalm reports no errors; formatters make no further changes on a second run.

- [ ] **Step 10: Commit**

```bash
cd /Users/tomas/Work/Monadial/CodeOSS/nexus
git checkout -- .deptrac.cache 2>/dev/null
git add packages/nexus-cluster-tcp/src/Membership/MembershipService.php \
        packages/nexus-cluster-tcp/src/Membership/MembershipActor.php \
        packages/nexus-cluster-tcp/tests/Unit/Membership/MembershipServiceTest.php
git commit --no-gpg-sign --no-verify -m "feat(cluster-tcp): feed phi detector at ingress time, not actor-processing time"
```

---

### Task 3: Stamp `observedAt` at frame ingress in `ClusterNode`

**Files:**
- Modify: `packages/nexus-cluster-tcp/src/ClusterNode.php` (`observeLiveness` ~line 521; verify the second `PeerLivenessObserved` construction at ~line 524 is the only one)

**Interfaces:**
- Consumes: `PeerLivenessObserved` 3-arg constructor (Task 1); `$this->system->clock()` (ActorSystem clock, the same instance the `MembershipActor` reads).
- Produces: no new public surface — internal wiring only.

- [ ] **Step 1: Confirm all `PeerLivenessObserved` construction sites**

Run:

```bash
docker compose exec -T php grep -rn "new PeerLivenessObserved" packages/nexus-cluster-tcp/src
```

Expected: a single site, inside `ClusterNode::observeLiveness()` (~line 524). If more appear, each must be updated the same way in this task.

- [ ] **Step 2: Stamp the ingress timestamp**

In `ClusterNode.php`, `observeLiveness()`. Before:

```php
    private function observeLiveness(NodeAddress $peerAddr): void
    {
        if ($this->livenessThrottle->shouldObserve($peerAddr->toPathPrefix(), hrtime(true))) {
            $this->membershipRef->tell(new PeerLivenessObserved($peerAddr, null));
        }
    }
```

After:

```php
    private function observeLiveness(NodeAddress $peerAddr): void
    {
        if ($this->livenessThrottle->shouldObserve($peerAddr->toPathPrefix(), hrtime(true))) {
            $this->membershipRef->tell(new PeerLivenessObserved($peerAddr, null, $this->system->clock()->now()));
        }
    }
```

`observeLiveness` runs synchronously in the recv coroutine at frame ingress and is throttled to at most one observation per detector sample interval per peer, so `$this->system->clock()->now()` here is the true, low-frequency arrival stamp. `$this->system` is the constructor-injected `ActorSystem` (ClusterNode.php:160); `clock()` is the same accessor already used to wire the membership actor (ClusterNode.php:304).

- [ ] **Step 3: Psalm + style gates**

```bash
docker compose exec -T php vendor/bin/psalm --no-cache packages/nexus-cluster-tcp/src/ClusterNode.php
docker compose exec -T php vendor/bin/php-cs-fixer fix packages/nexus-cluster-tcp/src/ClusterNode.php
```

Expected: Psalm clean; formatter makes no changes on a second run.

- [ ] **Step 4: Full cluster-tcp unit + loopback suites**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-cluster-tcp/tests/Unit
```

Expected: PASS. (No new unit test here — ingress stamping is exercised end-to-end by the soak in Task 4; the unit boundary was covered in Task 2.)

- [ ] **Step 5: Swoole integration suite**

```bash
make test-cluster
```

Expected: PASS (baseline 46 integration tests). This confirms the ingress wiring compiles and runs under real Swoole sockets.

- [ ] **Step 6: Commit**

```bash
cd /Users/tomas/Work/Monadial/CodeOSS/nexus
git checkout -- .deptrac.cache 2>/dev/null
git add packages/nexus-cluster-tcp/src/ClusterNode.php
git commit --no-gpg-sign --no-verify -m "feat(cluster-tcp): stamp liveness observedAt at frame ingress in the recv coroutine"
```

---

### Task 4: Acceptance — distributed mesh-soak at default phi

**Files:**
- Run: `tests/Performance/distributed/` mesh-soak harness (no source changes)

This is the authoritative gate. Unit-green from Tasks 1–3 is necessary but does **not** close the finding — the reverted #1 attempt was unit-green and soak-red.

- [ ] **Step 1: Run the 16-node distributed mesh-soak at DEFAULT phi**

Run the plain (non-observability) soak with `MESH_PHI_TUNING` explicitly unset (default phi threshold, no `minStdDev` workaround) — the same configuration that produced the ~210-suspicion/node baseline. The runner is `run.sh [durationSeconds] [payloadBytes]` (default 4 containers × 4 threads = 16 nodes):

```bash
cd /Users/tomas/Work/Monadial/CodeOSS/nexus/tests/Performance/distributed
env -u MESH_PHI_TUNING ./run.sh 180 1024
```

Use the SAME duration and payload the ~210 baseline was captured at so the comparison is apples-to-apples (180 s / 1024 B here; adjust both numbers if the baseline used different values). The script builds the mesh, waits `START_MARGIN`, runs the soak, and prints each node's verdict plus an aggregate throughput line. Tear down any prior stack first with `docker compose -f compose.yaml -p nexus-mesh-soak down -v --remove-orphans`.

- [ ] **Step 2: Evaluate the health lines against the pass bar**

Read each node's final verdict + health line (suspicion is broken into `conn`/`gossip`/`phi`). Pass criteria, ALL must hold:
- `suspected`/node collapses from ~210 into a low single-digit / near-zero range;
- `down = 0` on every node;
- aggregate throughput shows no regression versus the baseline (~686k msg/s observed pre-fix).

- [ ] **Step 3: Interpret and record**

- **If it passes:** the finding is closed. Append the result (before/after suspicion + down + throughput numbers) to `docs/superpowers/plans/2026-07-09-cluster-tcp-production-hardening.md` under the #1 / 1.A section, and update the memory note `cluster-tcp-incarnation-monotonicity` (or add a new `cluster-tcp-phi-ingress-timestamp` memory) with the confirmed mechanism + fix. Commit the doc update (`--no-gpg-sign --no-verify`).
- **If phi suspicion remains high:** the recv coroutine is itself CPU-starved so ingress stamps are also late — this is the evidence that escalates to Approach B (dedicated control lane). Do NOT patch further blindly; record the residual `phi` count and stop for a design decision.

- [ ] **Step 4: Final full-suite sanity**

```bash
make test-unit
make test-cluster
```

Expected: PASS. Confirms nothing regressed across packages.

---

## Notes for the implementer

- The change is additive: one required message field, one threaded parameter, one call site. There is no control-flow change to view evolution — `$now` still drives every membership state transition; only the phi detector's arrival clock moves to ingress time.
- If Psalm flags the `applyHandshake` double-`$now` as suspicious, it is intentional (documented in the spec): handshake keeps processing-time semantics.
- Keep the two timestamps distinct in your head: `observedAt` = when bytes hit the socket (detector only); `$now` = when the actor processes the message (everything else).
