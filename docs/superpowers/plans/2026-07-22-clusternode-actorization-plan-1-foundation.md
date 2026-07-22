# ClusterNode Actorization — Plan 1: Validation Net + Seams

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Land the refactor's safety net and seams — recovered 16-node soak harness, `WireFormat` protocol seam, injected per-subsystem metrics classes, and a Deptrac-guarded core↛transport boundary — with **zero behavior change**.

**Architecture:** Everything in this plan is additive: new interfaces/classes beside the existing monolith, recovered test tooling, and build-time boundary rules. Nothing consumes the new seams yet (Plans 2–5 do). Spec: `docs/superpowers/specs/2026-07-22-clusternode-actorization-design.md` §3.4, §3.5, §8.1.

**Tech Stack:** PHP 8.5 (Docker only — never host PHP), PHPUnit 13, Psalm level 1 (zero suppressions), Deptrac 4.6, GrumPHP pre-commit (cs-fixer, phpcs, psalm, phpunit — all four must pass on every commit).

## Global Constraints

- All commands run through Docker: `docker compose exec php …` or `make …`. Never `php`/`vendor/bin/*` on the host.
- Code style: PER-CS2.0 + Slevomat — string-key arrays sorted alphabetically, multi-line ternaries, blank line before `if`/`foreach`/`try`, `final` classes, `readonly` value objects, trailing commas, ordered imports (class, function, const — each alphabetical).
- Psalm level 1, **no `@psalm-suppress`** (repo zero-suppressions policy).
- **Zero behavior change** in this plan. No existing file's runtime behavior may differ; the only existing files modified are `deptrac.yaml`, `Makefile`, `.github/workflows/ci.yml`.
- Metric names, units, and attributes must match the inventory below **verbatim** — they are the documented public names (`website/docs/packages/cluster-tcp.md:206–238`).
- Branch: `refactor/cluster-node-actorization`. **The PR opens only after the audit-stack merge plan (`2026-07-22-audit-stack-merge-plan.md`) has executed** and this branch is rebased on `main`.
- Commit messages: conventional style, no Claude attribution.

---

### Task 1: Recover the 16-node soak harness

The complete harness lives on `feat/cluster-tcp` (tip `f5cbbe30`, on origin) — it was excluded from the squash-merge to `main`, never deleted. Recover, don't recreate.

**Files:**
- Recover: `tests/Performance/bin/cluster_tcp_soak.php` (2-node 300 s in-process soak), `tests/Performance/bin/cluster_tcp_bench_worker.php` + `tests/Performance/cluster_tcp_saturation.sh` (K-worker throughput sweep — source of the ~738k msg/s baseline), `tests/Performance/distributed/` (thread_mesh_node.php, roundtrip_node.php, run.sh, run-roundtrip.sh, run-observability.sh, compose.yaml, compose.roundtrip.yaml, compose.observability.yaml, README-observability.md, grafana/**)
- Recover: `docs/superpowers/reviews/2026-07-10-cluster-tcp-pr/roundtrip-demo-findings.md` (companion findings doc)
- Modify: `Makefile` (three new targets)

**Interfaces:**
- Consumes: `feat/cluster-tcp` git branch; package fixtures `packages/nexus-cluster-tcp/tests/Fixture/{Ping,Pong}.php` (already in-tree).
- Produces: `make soak-mesh`, `make soak-idle`, `make bench-saturation` — the standing validation gates for Plans 2–5.

- [ ] **Step 1: Recover the files**

```bash
git fetch origin feat/cluster-tcp
git checkout feat/cluster-tcp -- \
  tests/Performance/bin \
  tests/Performance/distributed \
  tests/Performance/cluster_tcp_saturation.sh \
  docs/superpowers/reviews/2026-07-10-cluster-tcp-pr/roundtrip-demo-findings.md
git status --short
```
Expected: ~16 new files staged, no modifications to existing files.

- [ ] **Step 2: Lint the recovered PHP against the current tree**

`main` has drifted ~±50 lines in `packages/nexus-cluster-tcp/src` since `feat/cluster-tcp`; verify the scripts still parse and their API calls still exist:

```bash
docker compose exec -T php php -l tests/Performance/bin/cluster_tcp_soak.php
docker compose exec -T php php -l tests/Performance/bin/cluster_tcp_bench_worker.php
docker compose exec -T php php -l tests/Performance/distributed/thread_mesh_node.php
docker compose exec -T php php -l tests/Performance/distributed/roundtrip_node.php
```
Expected: `No syntax errors detected` ×4. Then grep each script for `ClusterNode::boot(` / `->shutdown(` / `->view(` calls and confirm every named argument still exists in `packages/nexus-cluster-tcp/src/ClusterNode.php` (`boot()` signature at ~line 197). If a call drifted, fix the *script* (never the src) to the current API.

- [ ] **Step 3: Add Makefile targets**

Append after the existing `perf-http-swoole-threads` target (Makefile:89), matching the file's target style:

```make
soak-mesh: ## Loaded 16-node mesh soak: 4 containers x 4 threads, real TCP (~7 min)
	cd tests/Performance/distributed && ./run.sh

soak-idle: ## Idle 16-container mesh proof: zero traffic, default phi (~5 min)
	cd tests/Performance/distributed && ./run-roundtrip.sh

bench-saturation: ## Multi-process cluster throughput sweep K=1..16
	tests/Performance/cluster_tcp_saturation.sh
```

- [ ] **Step 4: Smoke-run the mesh soak (short window)**

```bash
cd tests/Performance/distributed && ./run.sh 60 512; cd -
```
Expected: 4 containers build and start, converge to 16/16, and every node prints a `PASS` verdict (suspicion growth ≤ 20 from post-convergence snapshot, down=0). The verdict rules are soak-won — never "fix" a failure by raising thresholds or detuning phi (`thread_mesh_node.php` runs production defaults by design).

- [ ] **Step 5: Handle style-gate friction on recovered files (if any)**

The recovered scripts predate the current style config. If GrumPHP rejects the commit on phpcs/cs-fixer grounds:

```bash
docker compose exec -T php vendor/bin/php-cs-fixer fix tests/Performance/bin tests/Performance/distributed
docker compose exec -T php vendor/bin/phpcbf tests/Performance/bin tests/Performance/distributed || true
```
Re-verify with `php -l` after auto-fixing. Do not hand-edit logic to satisfy style.

- [ ] **Step 6: Commit**

```bash
git add tests/Performance docs/superpowers/reviews Makefile
git commit -m "test(cluster-tcp): recover 16-node soak harness from feat/cluster-tcp

Recovered verbatim from f5cbbe30 (excluded from the original squash-merge):
loaded mesh soak, idle roundtrip proof, saturation sweep, observability
overlay. Adds make soak-mesh / soak-idle / bench-saturation. Validation
gate for the actorization series (spec §8.1)."
```

---

### Task 2: `WireFormat` seam + `MsgpackWireFormat`

**Files:**
- Create: `packages/nexus-cluster-tcp/src/Protocol/WireFormat.php`
- Create: `packages/nexus-cluster-tcp/src/Protocol/MsgpackWireFormat.php`
- Test: `packages/nexus-cluster-tcp/tests/Unit/Protocol/MsgpackWireFormatTest.php`

**Interfaces:**
- Consumes: `Payload\ControlFrameCodec` (`packHandshake/unpackHandshake/packHandshakeAck/unpackHandshakeAck/packGossip/unpackGossip/packLeave/unpackLeave`), `Payload\MessagePayloadCodec` (`pack/unpack`), `Monadial\Nexus\Serialization\Msgpack\MsgpackCodec`.
- Produces: `interface WireFormat` with ten methods (below) — the seam Plans 2–3 inject into the transport edge, and the contract `JsonWireFormat` implements later. `FrameCodec` (length-prefixed framing) is deliberately NOT part of `WireFormat` — framing is a transport concern, wire format is payload encoding (spec §3.4.4).

- [ ] **Step 1: Write the failing test**

`packages/nexus-cluster-tcp/tests/Unit/Protocol/MsgpackWireFormatTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Unit\Protocol;

use Monadial\Nexus\Cluster\Tcp\Payload\ControlFrameCodec;
use Monadial\Nexus\Cluster\Tcp\Payload\GossipPayload;
use Monadial\Nexus\Cluster\Tcp\Payload\Handshake;
use Monadial\Nexus\Cluster\Tcp\Payload\HandshakeAck;
use Monadial\Nexus\Cluster\Tcp\Payload\LeavePayload;
use Monadial\Nexus\Cluster\Tcp\Payload\MessagePayload;
use Monadial\Nexus\Cluster\Tcp\Payload\MessagePayloadCodec;
use Monadial\Nexus\Cluster\Tcp\Protocol\MsgpackWireFormat;
use Monadial\Nexus\Cluster\Tcp\Protocol\WireFormat;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MsgpackWireFormat::class)]
final class MsgpackWireFormatTest extends TestCase
{
    private MsgpackWireFormat $wire;

    protected function setUp(): void
    {
        $this->wire = new MsgpackWireFormat();
    }

    #[Test]
    public function implementsWireFormat(): void
    {
        self::assertInstanceOf(WireFormat::class, $this->wire);
    }

    #[Test]
    public function handshakeRoundTripsByteIdenticalToControlFrameCodec(): void
    {
        $handshake = new Handshake(
            clusterName: 'prod',
            node: ['application' => 'nexus', 'cluster' => 'prod', 'datacenter' => 'dc1', 'node' => 'node-1'],
            advertise: '127.0.0.1:7361',
            protocolVersion: 1,
            nonce: 'abc',
            issuedAt: 1234,
            mac: 'sig',
        );

        self::assertSame((new ControlFrameCodec())->packHandshake($handshake), $this->wire->packHandshake($handshake));
        self::assertEquals($handshake, $this->wire->unpackHandshake($this->wire->packHandshake($handshake)));
    }

    #[Test]
    public function handshakeAckRoundTrips(): void
    {
        $ack = new HandshakeAck(true, null, ['/cluster/prod/dc1/nexus/node-2' => '10.0.0.2:7361']);

        self::assertSame((new ControlFrameCodec())->packHandshakeAck($ack), $this->wire->packHandshakeAck($ack));
        self::assertEquals($ack, $this->wire->unpackHandshakeAck($this->wire->packHandshakeAck($ack)));
    }

    #[Test]
    public function gossipRoundTrips(): void
    {
        $gossip = new GossipPayload(
            [
                ['address' => '/cluster/prod/dc1/nexus/node-1', 'endpoint' => '10.0.0.1:7361', 'incarnation' => 3, 'status' => 1],
            ],
            [],
        );

        self::assertSame((new ControlFrameCodec())->packGossip($gossip), $this->wire->packGossip($gossip));
        self::assertEquals($gossip, $this->wire->unpackGossip($this->wire->packGossip($gossip)));
    }

    #[Test]
    public function leaveRoundTrips(): void
    {
        $leave = new LeavePayload('/cluster/prod/dc1/nexus/node-1');

        self::assertSame((new ControlFrameCodec())->packLeave($leave), $this->wire->packLeave($leave));
        self::assertEquals($leave, $this->wire->unpackLeave($this->wire->packLeave($leave)));
    }

    #[Test]
    public function messageRoundTripsByteIdenticalToMessagePayloadCodec(): void
    {
        $payload = new MessagePayload(
            targetPath: '/user/orders',
            messageType: 'demo.ping',
            body: '{"n":1}',
            correlationId: 'c-1',
            replyPath: '/user/reply',
            trace: ['traceparent' => '00-abc-def-01'],
        );

        self::assertSame((new MessagePayloadCodec())->pack($payload), $this->wire->packMessage($payload));
        self::assertEquals($payload, $this->wire->unpackMessage($this->wire->packMessage($payload)));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-cluster-tcp/tests/Unit/Protocol/MsgpackWireFormatTest.php
```
Expected: FAIL — `Class "Monadial\Nexus\Cluster\Tcp\Protocol\MsgpackWireFormat" not found`.

- [ ] **Step 3: Implement the interface and the msgpack implementation**

`packages/nexus-cluster-tcp/src/Protocol/WireFormat.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Protocol;

use Monadial\Nexus\Cluster\Tcp\Payload\GossipPayload;
use Monadial\Nexus\Cluster\Tcp\Payload\Handshake;
use Monadial\Nexus\Cluster\Tcp\Payload\HandshakeAck;
use Monadial\Nexus\Cluster\Tcp\Payload\LeavePayload;
use Monadial\Nexus\Cluster\Tcp\Payload\MessagePayload;
use Monadial\Nexus\Serialization\Exception\MessageDeserializationException;
use Monadial\Nexus\Serialization\Exception\MessageSerializationException;

/**
 * Codec family for cluster protocol payloads (handshake, gossip, leave,
 * message envelope) — the wire-format seam of the transport SPI (design spec
 * §3.4.4). Framing (FrameCodec) is deliberately NOT part of this contract:
 * framing belongs to the transport, wire format to payload encoding.
 *
 * Implementations must preserve forward compatibility: unpack* resolves
 * fields by key with defaults and ignores unknown keys. User message BODIES
 * are encoded by the orthogonal MessageSerializer, not by the wire format.
 */
interface WireFormat
{
    /** @throws MessageSerializationException */
    public function packHandshake(Handshake $handshake): string;

    /** @throws MessageDeserializationException */
    public function unpackHandshake(string $bytes): Handshake;

    /** @throws MessageSerializationException */
    public function packHandshakeAck(HandshakeAck $ack): string;

    /** @throws MessageDeserializationException */
    public function unpackHandshakeAck(string $bytes): HandshakeAck;

    /** @throws MessageSerializationException */
    public function packGossip(GossipPayload $gossip): string;

    /** @throws MessageDeserializationException */
    public function unpackGossip(string $bytes): GossipPayload;

    /** @throws MessageSerializationException */
    public function packLeave(LeavePayload $leave): string;

    /** @throws MessageDeserializationException */
    public function unpackLeave(string $bytes): LeavePayload;

    /** @throws MessageSerializationException */
    public function packMessage(MessagePayload $payload): string;

    /** @throws MessageDeserializationException */
    public function unpackMessage(string $bytes): MessagePayload;
}
```

`packages/nexus-cluster-tcp/src/Protocol/MsgpackWireFormat.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Protocol;

use Monadial\Nexus\Cluster\Tcp\Payload\ControlFrameCodec;
use Monadial\Nexus\Cluster\Tcp\Payload\GossipPayload;
use Monadial\Nexus\Cluster\Tcp\Payload\Handshake;
use Monadial\Nexus\Cluster\Tcp\Payload\HandshakeAck;
use Monadial\Nexus\Cluster\Tcp\Payload\LeavePayload;
use Monadial\Nexus\Cluster\Tcp\Payload\MessagePayload;
use Monadial\Nexus\Cluster\Tcp\Payload\MessagePayloadCodec;
use Monadial\Nexus\Serialization\Msgpack\MsgpackCodec;

/**
 * Default wire format: exactly the hand-rolled, perf-tuned msgpack codecs
 * grouped behind the WireFormat seam — zero hot-path change (spec §3.4.4).
 */
final readonly class MsgpackWireFormat implements WireFormat
{
    private ControlFrameCodec $control;
    private MessagePayloadCodec $message;

    public function __construct(MsgpackCodec $codec = new MsgpackCodec())
    {
        $this->control = new ControlFrameCodec($codec);
        $this->message = new MessagePayloadCodec($codec);
    }

    public function packHandshake(Handshake $handshake): string
    {
        return $this->control->packHandshake($handshake);
    }

    public function unpackHandshake(string $bytes): Handshake
    {
        return $this->control->unpackHandshake($bytes);
    }

    public function packHandshakeAck(HandshakeAck $ack): string
    {
        return $this->control->packHandshakeAck($ack);
    }

    public function unpackHandshakeAck(string $bytes): HandshakeAck
    {
        return $this->control->unpackHandshakeAck($bytes);
    }

    public function packGossip(GossipPayload $gossip): string
    {
        return $this->control->packGossip($gossip);
    }

    public function unpackGossip(string $bytes): GossipPayload
    {
        return $this->control->unpackGossip($bytes);
    }

    public function packLeave(LeavePayload $leave): string
    {
        return $this->control->packLeave($leave);
    }

    public function unpackLeave(string $bytes): LeavePayload
    {
        return $this->control->unpackLeave($bytes);
    }

    public function packMessage(MessagePayload $payload): string
    {
        return $this->message->pack($payload);
    }

    public function unpackMessage(string $bytes): MessagePayload
    {
        return $this->message->unpack($bytes);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-cluster-tcp/tests/Unit/Protocol/MsgpackWireFormatTest.php
```
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add packages/nexus-cluster-tcp/src/Protocol packages/nexus-cluster-tcp/tests/Unit/Protocol
git commit -m "feat(cluster-tcp): add WireFormat seam with byte-identical msgpack default

Groups the hand-rolled payload codecs behind the transport SPI's wire-format
contract (spec §3.4.4). MsgpackWireFormat delegates verbatim — byte identity
with ControlFrameCodec/MessagePayloadCodec is test-enforced. Framing stays
outside the seam. Consumed by the transport edge from Plan 2."
```

---

### Task 3: Metrics classes + telemetry guard

Extract every documented instrument into three eagerly-constructed, DI-injected `readonly` metrics classes plus one shared error-guard (spec §3.5). Standalone units in this plan — the new actors consume them in Plans 3–5; the monolith is NOT retrofitted.

**Files:**
- Create: `packages/nexus-cluster-tcp/src/Telemetry/ConnectionMetrics.php`
- Create: `packages/nexus-cluster-tcp/src/Telemetry/MembershipMetrics.php`
- Create: `packages/nexus-cluster-tcp/src/Telemetry/AskMetrics.php`
- Create: `packages/nexus-cluster-tcp/src/Telemetry/TelemetryGuard.php`
- Create: `packages/nexus-cluster-tcp/tests/Support/RecordingMeter.php`
- Test: `packages/nexus-cluster-tcp/tests/Unit/Telemetry/MetricsClassesTest.php`, `.../TelemetryGuardTest.php`

**Interfaces:**
- Consumes: `Monadial\Nexus\Observability\Metric\{Meter, Counter, Histogram, ObservableGauge}` — `Meter::counter(string $name, string $unit = '', string $description = ''): Counter`, `::histogram(...): Histogram`, `::observableGauge(string $name, callable $callback, ...): ObservableGauge`; `Monadial\Nexus\Observability\Trace\{Tracer, Span, SpanKind, NoopSpan}` — `Tracer::startSpan(string $name, SpanKind $kind = SpanKind::Internal, array $attributes = [], ?Context $parent = null): Span`. (Copy the `Context` import line from `packages/nexus-observability/src/Trace/Tracer.php` — use exactly the type that signature uses.)
- Produces: `new ConnectionMetrics(Meter)`, `new MembershipMetrics(Meter)`, `new AskMetrics(Meter, callable(): int $pendingCount)` with public readonly instrument properties named below; `TelemetryGuard::safely(callable): void`, `::startSpan(Tracer, string, SpanKind, array, ?Context): Span` (returns `NoopSpan` on tracer failure), `::attribute(Span, string, string|int|float|bool): void`, `::recordException(Span, Throwable): void`, `::end(Span): void`. Plans 3–5 wire these into every new actor.

**Instrument inventory (names/units verbatim — the enforced public contract):**

| Class / property | Instrument | Name | Unit |
|---|---|---|---|
| ConnectionMetrics `$framesSent` | counter | `nexus.cluster.frames.sent` | `{frame}` |
| ConnectionMetrics `$framesReceived` | counter | `nexus.cluster.frames.received` | `{frame}` |
| ConnectionMetrics `$framesBuffered` | counter | `nexus.cluster.frames.buffered` | `{frame}` |
| ConnectionMetrics `$framesDropped` | counter | `nexus.cluster.frames.dropped` | `{frame}` |
| ConnectionMetrics `$framesDecodeFailed` | counter | `nexus.cluster.frames.decode_failed` | `{frame}` |
| ConnectionMetrics `$framesHandlerFailed` | counter | `nexus.cluster.frames.handler_failed` | `{frame}` |
| ConnectionMetrics `$controlSendFailed` | counter | `nexus.cluster.control_send.failed` | `{send}` |
| ConnectionMetrics `$handshakeRejected` | counter | `nexus.cluster.handshake.rejected` | `{handshake}` |
| ConnectionMetrics `$socketWriteFailed` | counter | `nexus.cluster.socket_write.failed` | `{write}` |
| ConnectionMetrics `$messagesSent` | counter | `nexus.cluster.messages.sent` | `{message}` |
| ConnectionMetrics `$messagesReceived` | counter | `nexus.cluster.messages.received` | `{message}` |
| ConnectionMetrics `$messagesLocalShortCircuit` | counter | `nexus.cluster.messages.local_shortcircuit` | `{message}` |
| ConnectionMetrics `$messagesUnroutable` | counter | `nexus.cluster.messages.unroutable` | `{message}` |
| ConnectionMetrics `$sendBufferDropped` | counter | `nexus.cluster.send_buffer.dropped` | `{message}` |
| ConnectionMetrics `$bytesSent` | histogram | `nexus.cluster.bytes.sent` | `By` |
| ConnectionMetrics `$bytesReceived` | histogram | `nexus.cluster.bytes.received` | `By` |
| MembershipMetrics `$nodesSuspected` | counter | `nexus.cluster.nodes.suspected` | `{node}` |
| MembershipMetrics `$nodesRecovered` | counter | `nexus.cluster.nodes.recovered` | `{node}` |
| MembershipMetrics `$nodesPruned` | counter | `nexus.cluster.nodes.pruned` | `{node}` |
| MembershipMetrics `$heartbeatsReceived` | counter | `nexus.cluster.heartbeats.received` | `{heartbeat}` |
| MembershipMetrics `$gossipRounds` | counter | `nexus.cluster.gossip.rounds` | `{round}` |
| AskMetrics `$asksSent` | counter | `nexus.cluster.asks.sent` | `{message}` |
| AskMetrics `$asksResolved` | counter | `nexus.cluster.asks.resolved` | `{message}` |
| AskMetrics `$asksTimedOut` | counter | `nexus.cluster.asks.timed_out` | `{message}` |
| AskMetrics `$asksCapacityRejected` | counter | `nexus.cluster.asks.capacity_rejected` | `{message}` |
| AskMetrics `$askDuration` | histogram | `nexus.cluster.ask.duration` | `ms` |
| AskMetrics `$asksPending` | observableGauge | `nexus.cluster.asks.pending` | `{message}` |

`nexus.cluster.socket_write.failed` is the one NEW name (spec §4.3 — post-admission write failures); it gets documented in Plan 5's docs task. Descriptions: reuse the existing description strings from the current creation sites where present (e.g. `FrameIngress.php:122-129` "Cluster frames received from remote peers"); write matching one-liners for the rest.

- [ ] **Step 1: Write the recording test double**

`packages/nexus-cluster-tcp/tests/Support/RecordingMeter.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Support;

use Monadial\Nexus\Observability\Metric\Counter;
use Monadial\Nexus\Observability\Metric\Histogram;
use Monadial\Nexus\Observability\Metric\Meter;
use Monadial\Nexus\Observability\Metric\NoopMeter;
use Monadial\Nexus\Observability\Metric\ObservableGauge;
use Monadial\Nexus\Observability\Metric\UpDownCounter;

/**
 * Records instrument creation (name => unit) so tests can pin the public
 * metric names. Instrument instances delegate to the shared no-ops.
 */
final class RecordingMeter implements Meter
{
    /** @var array<string, string> name => unit */
    public array $counters = [];

    /** @var array<string, string> name => unit */
    public array $gauges = [];

    /** @var array<string, callable(): (int|float)> name => callback */
    public array $gaugeCallbacks = [];

    /** @var array<string, string> name => unit */
    public array $histograms = [];

    private readonly NoopMeter $noop;

    public function __construct()
    {
        $this->noop = new NoopMeter();
    }

    public function counter(string $name, string $unit = '', string $description = ''): Counter
    {
        $this->counters[$name] = $unit;

        return $this->noop->counter($name, $unit, $description);
    }

    public function upDownCounter(string $name, string $unit = '', string $description = ''): UpDownCounter
    {
        return $this->noop->upDownCounter($name, $unit, $description);
    }

    public function histogram(string $name, string $unit = '', string $description = ''): Histogram
    {
        $this->histograms[$name] = $unit;

        return $this->noop->histogram($name, $unit, $description);
    }

    public function observableGauge(string $name, callable $callback, string $unit = '', string $description = ''): ObservableGauge
    {
        $this->gaugeCallbacks[$name] = $callback;
        $this->gauges[$name] = $unit;

        return $this->noop->observableGauge($name, $callback, $unit, $description);
    }
}
```

(Verify `UpDownCounter`'s exact signature against `packages/nexus-observability/src/Metric/Meter.php` before committing; mirror any parameter drift exactly.)

- [ ] **Step 2: Write the failing tests**

`packages/nexus-cluster-tcp/tests/Unit/Telemetry/MetricsClassesTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Unit\Telemetry;

use Monadial\Nexus\Cluster\Tcp\Telemetry\AskMetrics;
use Monadial\Nexus\Cluster\Tcp\Telemetry\ConnectionMetrics;
use Monadial\Nexus\Cluster\Tcp\Telemetry\MembershipMetrics;
use Monadial\Nexus\Cluster\Tcp\Tests\Support\RecordingMeter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_keys;

#[CoversClass(AskMetrics::class)]
#[CoversClass(ConnectionMetrics::class)]
#[CoversClass(MembershipMetrics::class)]
final class MetricsClassesTest extends TestCase
{
    #[Test]
    public function connectionMetricsCreatesEveryDocumentedInstrumentEagerly(): void
    {
        $meter = new RecordingMeter();
        new ConnectionMetrics($meter);

        self::assertSame(
            [
                'nexus.cluster.frames.sent' => '{frame}',
                'nexus.cluster.frames.received' => '{frame}',
                'nexus.cluster.frames.buffered' => '{frame}',
                'nexus.cluster.frames.dropped' => '{frame}',
                'nexus.cluster.frames.decode_failed' => '{frame}',
                'nexus.cluster.frames.handler_failed' => '{frame}',
                'nexus.cluster.control_send.failed' => '{send}',
                'nexus.cluster.handshake.rejected' => '{handshake}',
                'nexus.cluster.socket_write.failed' => '{write}',
                'nexus.cluster.messages.sent' => '{message}',
                'nexus.cluster.messages.received' => '{message}',
                'nexus.cluster.messages.local_shortcircuit' => '{message}',
                'nexus.cluster.messages.unroutable' => '{message}',
                'nexus.cluster.send_buffer.dropped' => '{message}',
            ],
            $meter->counters,
        );
        self::assertSame(
            ['nexus.cluster.bytes.sent' => 'By', 'nexus.cluster.bytes.received' => 'By'],
            $meter->histograms,
        );
    }

    #[Test]
    public function membershipMetricsCreatesEveryDocumentedInstrumentEagerly(): void
    {
        $meter = new RecordingMeter();
        new MembershipMetrics($meter);

        self::assertSame(
            [
                'nexus.cluster.nodes.suspected' => '{node}',
                'nexus.cluster.nodes.recovered' => '{node}',
                'nexus.cluster.nodes.pruned' => '{node}',
                'nexus.cluster.heartbeats.received' => '{heartbeat}',
                'nexus.cluster.gossip.rounds' => '{round}',
            ],
            $meter->counters,
        );
    }

    #[Test]
    public function askMetricsCreatesInstrumentsAndWiresThePendingGauge(): void
    {
        $meter = new RecordingMeter();
        new AskMetrics($meter, static fn(): int => 7);

        self::assertSame(
            [
                'nexus.cluster.asks.sent' => '{message}',
                'nexus.cluster.asks.resolved' => '{message}',
                'nexus.cluster.asks.timed_out' => '{message}',
                'nexus.cluster.asks.capacity_rejected' => '{message}',
            ],
            $meter->counters,
        );
        self::assertSame(['nexus.cluster.ask.duration' => 'ms'], $meter->histograms);
        self::assertSame(['nexus.cluster.asks.pending' => '{message}'], $meter->gauges);
        self::assertSame(7, ($meter->gaugeCallbacks['nexus.cluster.asks.pending'])());
    }

    #[Test]
    public function instrumentPropertiesAreExposedForRecording(): void
    {
        $metrics = new ConnectionMetrics(new RecordingMeter());

        $metrics->framesSent->add(1, ['frame.type' => 'message']);
        $metrics->bytesSent->record(128);

        $this->addToAssertionCount(1);
    }
}
```

Note: `assertSame` on the maps pins insertion ORDER too — construct instruments in exactly the table's order. (String-key literal arrays in *this test* must stay alphabetical per Slevomat only when written as literals — here the expectation arrays mirror creation order; if phpcs flags them, switch the assertion to compare `array_keys(...)` with a plain list plus a separate unit map, keeping the same coverage.)

`packages/nexus-cluster-tcp/tests/Unit/Telemetry/TelemetryGuardTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Unit\Telemetry;

use Monadial\Nexus\Cluster\Tcp\Telemetry\TelemetryGuard;
use Monadial\Nexus\Observability\Trace\NoopSpan;
use Monadial\Nexus\Observability\Trace\NoopTracer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(TelemetryGuard::class)]
final class TelemetryGuardTest extends TestCase
{
    #[Test]
    public function safelySwallowsThrowables(): void
    {
        $guard = new TelemetryGuard();

        $guard->safely(static fn() => throw new RuntimeException('telemetry broke'));

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function safelyRunsTheCallable(): void
    {
        $guard = new TelemetryGuard();
        $ran = false;

        $guard->safely(static function () use (&$ran): void {
            $ran = true;
        });

        self::assertTrue($ran);
    }

    #[Test]
    public function startSpanDelegatesToTracer(): void
    {
        $guard = new TelemetryGuard();

        $span = $guard->startSpan(new NoopTracer(), 'cluster.handshake');

        self::assertInstanceOf(NoopSpan::class, $span);
    }

    #[Test]
    public function spanHelpersSwallowThrowables(): void
    {
        $guard = new TelemetryGuard();
        $span = new NoopSpan();

        $guard->attribute($span, 'nexus.cluster.peer', 'node-1');
        $guard->recordException($span, new RuntimeException('x'));
        $guard->end($span);

        $this->addToAssertionCount(1);
    }
}
```

(If `NoopTracer::startSpan` returns a shared `NoopSpan` instance rather than the class itself, keep the `assertInstanceOf` — it covers both.)

- [ ] **Step 3: Run tests to verify they fail**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-cluster-tcp/tests/Unit/Telemetry
```
Expected: FAIL — classes not found.

- [ ] **Step 4: Implement the four classes**

`ConnectionMetrics.php` (pattern for all three — constructor creates instruments eagerly, in table order, with the exact names/units and one-line descriptions):

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Telemetry;

use Monadial\Nexus\Observability\Metric\Counter;
use Monadial\Nexus\Observability\Metric\Histogram;
use Monadial\Nexus\Observability\Metric\Meter;

/**
 * Connection/frame/delivery instruments, created eagerly at wiring time and
 * injected as a plain instance (spec §3.5). This class is the single home of
 * the documented metric names — website/docs/packages/cluster-tcp.md.
 */
final readonly class ConnectionMetrics
{
    public Counter $framesSent;
    public Counter $framesReceived;
    public Counter $framesBuffered;
    public Counter $framesDropped;
    public Counter $framesDecodeFailed;
    public Counter $framesHandlerFailed;
    public Counter $controlSendFailed;
    public Counter $handshakeRejected;
    public Counter $socketWriteFailed;
    public Counter $messagesSent;
    public Counter $messagesReceived;
    public Counter $messagesLocalShortCircuit;
    public Counter $messagesUnroutable;
    public Counter $sendBufferDropped;
    public Histogram $bytesSent;
    public Histogram $bytesReceived;

    public function __construct(Meter $meter)
    {
        $this->framesSent = $meter->counter('nexus.cluster.frames.sent', '{frame}', 'Cluster frames admitted to a peer link');
        $this->framesReceived = $meter->counter('nexus.cluster.frames.received', '{frame}', 'Cluster frames received from remote peers');
        $this->framesBuffered = $meter->counter('nexus.cluster.frames.buffered', '{frame}', 'Cluster frames buffered while a peer reconnects');
        $this->framesDropped = $meter->counter('nexus.cluster.frames.dropped', '{frame}', 'Cluster frames dropped without delivery');
        $this->framesDecodeFailed = $meter->counter('nexus.cluster.frames.decode_failed', '{frame}', 'Cluster frames that failed payload decoding');
        $this->framesHandlerFailed = $meter->counter('nexus.cluster.frames.handler_failed', '{frame}', 'Cluster frames whose handler threw');
        $this->controlSendFailed = $meter->counter('nexus.cluster.control_send.failed', '{send}', 'Control frame sends that failed');
        $this->handshakeRejected = $meter->counter('nexus.cluster.handshake.rejected', '{handshake}', 'Handshakes rejected during admission');
        $this->socketWriteFailed = $meter->counter('nexus.cluster.socket_write.failed', '{write}', 'Socket writes that failed after admission');
        $this->messagesSent = $meter->counter('nexus.cluster.messages.sent', '{message}', 'User messages sent to remote peers');
        $this->messagesReceived = $meter->counter('nexus.cluster.messages.received', '{message}', 'User messages received from remote peers');
        $this->messagesLocalShortCircuit = $meter->counter('nexus.cluster.messages.local_shortcircuit', '{message}', 'Messages delivered locally without the wire');
        $this->messagesUnroutable = $meter->counter('nexus.cluster.messages.unroutable', '{message}', 'Inbound messages with no local target');
        $this->sendBufferDropped = $meter->counter('nexus.cluster.send_buffer.dropped', '{message}', 'Sends dropped for lack of a routable endpoint');
        $this->bytesSent = $meter->histogram('nexus.cluster.bytes.sent', 'By', 'Encoded payload bytes sent');
        $this->bytesReceived = $meter->histogram('nexus.cluster.bytes.received', 'By', 'Payload bytes received');
    }
}
```

`MembershipMetrics.php` — same shape: properties `$nodesSuspected`, `$nodesRecovered`, `$nodesPruned`, `$heartbeatsReceived`, `$gossipRounds`, created in that order with the table's names/units and descriptions "Peers marked suspected", "Peers recovered from suspicion", "Peers pruned after Down", "Peer liveness observations", "Gossip rounds sent".

`AskMetrics.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Telemetry;

use Monadial\Nexus\Observability\Metric\Counter;
use Monadial\Nexus\Observability\Metric\Histogram;
use Monadial\Nexus\Observability\Metric\Meter;
use Monadial\Nexus\Observability\Metric\ObservableGauge;

/**
 * Ask/correlation instruments (spec §3.5). The pending gauge observes the
 * registry's live count via the injected callback — no lazy creation.
 */
final readonly class AskMetrics
{
    public Counter $asksSent;
    public Counter $asksResolved;
    public Counter $asksTimedOut;
    public Counter $asksCapacityRejected;
    public Histogram $askDuration;
    public ObservableGauge $asksPending;

    /**
     * @param callable(): int $pendingCount
     */
    public function __construct(Meter $meter, callable $pendingCount)
    {
        $this->asksSent = $meter->counter('nexus.cluster.asks.sent', '{message}', 'Remote asks initiated');
        $this->asksResolved = $meter->counter('nexus.cluster.asks.resolved', '{message}', 'Remote asks resolved with a reply');
        $this->asksTimedOut = $meter->counter('nexus.cluster.asks.timed_out', '{message}', 'Remote asks that timed out');
        $this->asksCapacityRejected = $meter->counter('nexus.cluster.asks.capacity_rejected', '{message}', 'Asks rejected at registry capacity');
        $this->askDuration = $meter->histogram('nexus.cluster.ask.duration', 'ms', 'Remote ask round-trip duration');
        $this->asksPending = $meter->observableGauge('nexus.cluster.asks.pending', $pendingCount, '{message}', 'Asks currently awaiting a reply');
    }
}
```

`TelemetryGuard.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Telemetry;

use Monadial\Nexus\Observability\Trace\NoopSpan;
use Monadial\Nexus\Observability\Trace\Span;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Observability\Trace\Tracer;
use Throwable;

/**
 * Swallow-safe telemetry helper: a broken tracer or meter must never disrupt
 * cluster operations (spec §3.5). Replaces the per-class safely()/safeSpan*
 * copies.
 */
final readonly class TelemetryGuard
{
    /**
     * @param callable(): mixed $fn
     */
    public function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable) {
            // Telemetry must never break cluster operations.
        }
    }

    /**
     * @param array<string, scalar> $attributes
     */
    public function startSpan(Tracer $tracer, string $name, SpanKind $kind = SpanKind::Internal, array $attributes = []): Span
    {
        try {
            return $tracer->startSpan($name, $kind, $attributes);
        } catch (Throwable) {
            return new NoopSpan();
        }
    }

    public function attribute(Span $span, string $key, string|int|float|bool $value): void
    {
        try {
            $span->setAttribute($key, $value);
        } catch (Throwable) {
        }
    }

    public function recordException(Span $span, Throwable $exception): void
    {
        try {
            $span->recordException($exception);
        } catch (Throwable) {
        }
    }

    public function end(Span $span): void
    {
        try {
            $span->end();
        } catch (Throwable) {
        }
    }
}
```

(A `?Context $parent` parameter for consumer-span parenting is added in Plan 3 when the first consumer needs it — YAGNI here; note it in the commit body so Plan 3's author knows.)

- [ ] **Step 5: Run tests to verify they pass**

```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-cluster-tcp/tests/Unit/Telemetry
```
Expected: PASS (8 tests). If the `assertSame` order assertions fail on phpcs's alphabetical-array rule vs creation order, apply the fallback noted in Step 2.

- [ ] **Step 6: Commit**

```bash
git add packages/nexus-cluster-tcp/src/Telemetry packages/nexus-cluster-tcp/tests/Support/RecordingMeter.php packages/nexus-cluster-tcp/tests/Unit/Telemetry
git commit -m "feat(cluster-tcp): extract injected metrics classes and telemetry guard

ConnectionMetrics/MembershipMetrics/AskMetrics create every documented
instrument eagerly at wiring time (names test-pinned, incl. the new
nexus.cluster.socket_write.failed); TelemetryGuard replaces the seven
copy-pasted safely()/safeSpan blocks. Consumed by the new actors from
Plan 3; the monolith is intentionally not retrofitted. Spec §3.5.
Note for Plan 3: TelemetryGuard::startSpan grows ?Context parent when the
first consumer-span call site lands."
```

---

### Task 4: Deptrac core↛transport boundary + fixture guard

Replace the single package-level `ClusterTcp` layer with three intra-package layers, and guard the new forbidden edge ARCH-002-style (self-cleaning intentional-violation fixture).

**Files:**
- Modify: `deptrac.yaml` (layer at :75–78, ruleset entry at :256–263)
- Create: `bin/verify-cluster-boundary.php` (adapted copy of `bin/verify-runtime-core-boundary.php`)
- Modify: `Makefile` (extend `boundary-check`), `.github/workflows/ci.yml` (one new step after :113–114)

**Interfaces:**
- Consumes: existing Deptrac config structure (directory collectors + allow-list ruleset), `bin/verify-runtime-core-boundary.php` as the template, CI static-analysis job.
- Produces: layers `ClusterTcpCore`, `ClusterTcpTransport`, `ClusterTcpWiring`; the enforced rule *core must not import `Loopback/`/`Swoole/`*; `make cluster-boundary-check`.

- [ ] **Step 1: Rewrite the deptrac layer + ruleset**

In `deptrac.yaml`, DELETE the `ClusterTcp` layer block and its ruleset entry (keeping both old and new would put every class in two layers and produce spurious violations). ADD under `layers:`:

```yaml
    - name: ClusterTcpTransport
      collectors:
        - type: directory
          value: packages/nexus-cluster-tcp/src/(Loopback|Swoole)/.*

    - name: ClusterTcpWiring
      collectors:
        - type: directory
          value: packages/nexus-cluster-tcp/src/ClusterNode\.php

    - name: ClusterTcpCore
      collectors:
        - type: bool
          must:
            - type: directory
              value: packages/nexus-cluster-tcp/src/.*
          must_not:
            - type: directory
              value: packages/nexus-cluster-tcp/src/(Loopback|Swoole)/.*
            - type: directory
              value: packages/nexus-cluster-tcp/src/ClusterNode\.php
```

ADD under `ruleset:` (replacing the old `ClusterTcp:` entry):

```yaml
    ClusterTcpCore:
      - Cluster
      - Core
      - Observability
      - Runtime
      - Serialization
      - SerializationMsgpack
    ClusterTcpTransport:
      - Cluster
      - ClusterTcpCore
      - Core
      - Observability
      - Runtime
      - Serialization
    ClusterTcpWiring:
      - Cluster
      - ClusterTcpCore
      - ClusterTcpTransport
      - Core
      - Observability
      - Runtime
      - RuntimeSwoole
      - Serialization
      - SerializationMsgpack
```

Add an inline comment above the three layers mirroring the ARCH-002 comment style: `# ClusterTcpCore must not depend on ClusterTcpTransport (spec §3.4): the actor core is transport-neutral; ClusterNode.php is the composition root and the only file allowed to see both plus RuntimeSwoole. Enforced by bin/verify-cluster-boundary.php.`

- [ ] **Step 2: Run deptrac — clean tree must pass**

```bash
docker compose exec -T php php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/deptrac analyse --no-progress
```
Expected: 0 violations. (Verified assumptions: `ClusterNode.php` is the only non-transport file importing `Loopback\`/`Swoole\`, and the only file importing `Runtime\Swoole\SwooleRuntime`. If a violation appears, a new import crept in since 2026-07-22 — evaluate it against the spec boundary before touching the ruleset.)

- [ ] **Step 3: Create the fixture guard script**

Copy `bin/verify-runtime-core-boundary.php` to `bin/verify-cluster-boundary.php` and apply exactly these changes:

1. Fixture path → `packages/nexus-cluster-tcp/src/Membership/__ClusterCoreTransportBoundaryFixture.php`.
2. Fixture heredoc content →

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Membership;

use Monadial\Nexus\Cluster\Tcp\Loopback\LoopbackHub;

/**
 * INTENTIONAL core->transport boundary violation, written and removed by
 * bin/verify-cluster-boundary.php. If you are reading this in a diff, the
 * verifier crashed before cleanup — delete this file; it must never be committed.
 */
final class __ClusterCoreTransportBoundaryFixture
{
    public const string TRANSPORT = LoopbackHub::class;
}
```

3. Gate 1 expected-output substring → `must not depend on Monadial\Nexus\Cluster\Tcp\Loopback`.
4. DELETE the Gate 2 block (`bin/check-package-deps.php`) and its exit handling entirely — both namespaces live in one composer package, so package-dependency checking cannot see intra-package edges. Renumber the diagnostics accordingly.
5. Update the header docblock to name the cluster boundary and spec §3.4.

Keep everything else identical: refuse-if-fixture-exists (exit 2), shutdown-function cleanup, `php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/deptrac analyse --no-progress`, exit 1 with diagnostics if deptrac fails to flag the fixture.

- [ ] **Step 4: Run the guard — it must prove the gate bites**

```bash
docker compose exec -T php php bin/verify-cluster-boundary.php
```
Expected: exit 0 with output confirming deptrac REJECTED the injected violation and the fixture was cleaned up. `git status` afterwards: clean.

- [ ] **Step 5: Wire into Make and CI**

`Makefile` — add below `boundary-check` (:98–99):

```make
cluster-boundary-check: ## Prove the cluster core->transport Deptrac gate bites
	$(DC) php bin/verify-cluster-boundary.php
```

`.github/workflows/ci.yml` — in the static-analysis job, directly after the "Runtime→Core boundary fixture" step (:113–114), matching its style:

```yaml
      - name: Cluster core→transport boundary fixture
        run: docker run --rm -v "$PWD":/app -w /app nexus-php-swoole:ci php bin/verify-cluster-boundary.php
```

- [ ] **Step 6: Commit**

```bash
git add deptrac.yaml bin/verify-cluster-boundary.php Makefile .github/workflows/ci.yml
git commit -m "build(deptrac): enforce cluster core->transport boundary with fixture guard

Splits the ClusterTcp layer into Core/Transport/Wiring; core must not see
Loopback|Swoole, RuntimeSwoole is confined to the ClusterNode composition
root. Guarded ARCH-002-style by bin/verify-cluster-boundary.php (Gate 1
only - intra-package edges are invisible to check-package-deps). Spec §3.4."
```

---

### Task 5: Full verification + baseline capture

**Files:** none created — verification only. Produces the PR-description baseline block.

- [ ] **Step 1: Full local gates**

```bash
make psalm && make phpcs && make cs
make test-unit
make test-fiber && make test-swoole && make test-cluster
```
Expected: all green. Any failure traces to this plan's four commits — fix before proceeding (behavior change is a plan violation).

- [ ] **Step 2: Capture baselines (recorded in the PR description, not committed)**

```bash
docker compose exec -T php-swoole php tests/Performance/hotpath_breakdown.php
make bench-saturation
make soak-idle
make soak-mesh
```
Record: per-section µs/op from hotpath_breakdown, aggregate msg/s at the best K from the saturation sweep (expect ~738k msg/s ballpark), idle + loaded soak verdicts (16/16 PASS, zero false Down at default phi). These numbers are the Plans 2–5 regression reference (spec §8.2: ≥90 %).

- [ ] **Step 3: Open the PR — gated on the audit-stack merge**

Precondition: `2026-07-22-audit-stack-merge-plan.md` executed, this branch rebased on `main` (roadmap Prerequisites). Then:

```bash
git push -u origin refactor/cluster-node-actorization
gh pr create --title "refactor(cluster-tcp): Plan 1 — validation net + seams" \
  --body-file - <<'EOF'
Plan 1 of the ClusterNode actorization series (spec: docs/superpowers/specs/2026-07-22-clusternode-actorization-design.md, roadmap + plan: docs/superpowers/plans/).

- Recovered 16-node soak harness from feat/cluster-tcp (make soak-mesh / soak-idle / bench-saturation)
- WireFormat seam + byte-identical MsgpackWireFormat (spec §3.4.4)
- Injected metrics classes + TelemetryGuard, names test-pinned (spec §3.5)
- Deptrac ClusterTcpCore ↛ ClusterTcpTransport + fixture guard (spec §3.4)
- Zero behavior change; existing tests untouched

Baselines (Plans 2–5 regression reference):
<hotpath table>
<saturation msg/s>
<soak verdicts>

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
```

---

## Self-review notes (spec coverage)

- Spec §8.1 (harness gate) → Task 1. §3.4.4 (wire format) → Task 2. §3.5 (metrics + guard) → Task 3. §3.4 packaging boundary → Task 4. §8.2 baseline → Task 5.
- Deliberately out of Plan 1 (per roadmap): transport SPI contracts and pumps (Plan 2), any consumer of `WireFormat`/metrics classes (Plans 3–5), `Transport/` namespace moves (Plan 2 — note: the Deptrac layers already match the CURRENT layout `Loopback|Swoole`; Plan 2 updates the regexes when it moves them under `Transport/`).
- Type-consistency: `WireFormat` method names match `ControlFrameCodec`'s exactly plus `packMessage`/`unpackMessage`; metrics property names ↔ inventory table ↔ tests are 1:1.
