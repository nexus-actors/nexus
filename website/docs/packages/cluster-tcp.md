---
title: nexus-cluster-tcp
related:
  - packages/cluster
  - packages/serialization-msgpack
  - packages/observability
  - guides/clustering-over-tcp
---

# nexus-cluster-tcp

Swoole TCP mesh clustering for Nexus — a full `nexus-cluster` implementation that lets actor systems on different machines discover each other via gossip, detect failures through phi-accrual, and exchange messages with the same `tell`/`ask` API used for local actors.

## What's in this package

| Class / Interface | Purpose |
|---|---|
| `ClusterNode` | Bootstrap entry point: wires transport, membership, and message routing into a running cluster node |
| `ClusterTopology` | Immutable configuration VO: identity, endpoints, seeds, failure-detection knobs, optional TLS |
| `NodeEndpoint` | Network endpoint VO (`host:port`); `fromString()` factory with port-range validation |
| `EndpointResolver` / `MapEndpointResolver` / `MutableEndpointRegistry` | Resolve `NodeAddress` → `NodeEndpoint`; immutable map and mutable runtime registry |
| `ClusterRef<T>` | Location-transparent `ActorRef<T>`: short-circuits locally, serialises to TCP for remote peers; supports `tell` and `ask` |
| `MembershipActor` + phi-accrual detector | Internal gossip loop and Hayashibara phi-accrual failure detector; marks peers `Up`, `Suspect`, `Down` |
| `TlsConfig` | Optional Swoole SSL configuration for encrypted peer connections |
| `ClusterView` | Immutable membership snapshot returned by `ClusterNode::view()` |
| PSR-14 events | `NodeUp`, `NodeDown`, `NodeSuspected`, `PeerConnected`, `PeerDisconnected` — dispatched via `ActorSystem`'s event dispatcher |

## Install

```bash title="terminal"
composer require nexus-actors/cluster-tcp
```

The loopback transport and unit tests run without Swoole. Production TCP transport requires it:

```bash title="terminal (production)"
composer require nexus-actors/runtime-swoole
# ext-swoole ≥ 6.2.1 compiled with --enable-swoole-thread
```

## Quick example

```php title="src/Cluster/GreeterNode.php" verify:lint-only
use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\ClusterNode;
use Monadial\Nexus\Cluster\Tcp\ClusterTopology;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use Monadial\Nexus\Serialization\TypeRegistry;

$runtime = new SwooleRuntime();
$system  = ActorSystem::create('my-cluster', $runtime);

$topology = ClusterTopology::create(
    clusterName:       'production',
    self:              new NodeAddress('production', 'eu', 'orders', 'node-1'),
    bindEndpoint:      NodeEndpoint::fromString('0.0.0.0:7355'),
    advertiseEndpoint: NodeEndpoint::fromString('10.0.0.1:7355'),
    seeds:             [NodeEndpoint::fromString('10.0.0.2:7355')],
);

// Register message types for cross-node serialisation (#[MessageType] required)
$registry = new TypeRegistry();
$registry->registerFromAttribute(OrderPlaced::class);
$registry->registerFromAttribute(OrderConfirmed::class);

$node = ClusterNode::boot($system, $topology, $registry);

// Expose a local actor for remote delivery
$processorRef = $system->spawn(Props::fromBehavior($processorBehavior), 'order-processor');
$node->expose($processorRef);

// Get a location-transparent ref to the same-named actor on node-2.
// Both nodes spawn 'order-processor'; paths are agreed by naming convention
// (a receptionist service-registry arrives in C2).
$remoteRef = $node->refFor(
    new NodeAddress('production', 'eu', 'orders', 'node-2'),
    $processorRef->path(),
);

// tell — fire-and-forget; short-circuits if target is self, serialises for remote
$remoteRef->tell(new OrderPlaced('ord-42'));

// ask — registers a correlation slot, returns a Future
$confirmed = $remoteRef->ask(new OrderPlaced('ord-43'), Duration::seconds(5))->await();

$system->run();
```

All messages sent across the cluster must carry `#[MessageType]` and be registered in the `TypeRegistry` passed to `boot()`. The same registry also covers the internal cluster wire protocol (handshake, gossip, leave frames), so one shared registry handles everything.

`ClusterNode::boot()` must be called after `ActorSystem::create()` and before `$system->run()`. The membership actor schedules its own heartbeat and gossip ticks, so the node is self-driving once the event loop starts.

## ClusterRef: location-transparent tell and ask

`ClusterNode::refFor(NodeAddress $node, ActorPath $path): ClusterRef` returns a ref that behaves identically to a local `ActorRef`:

- **`tell(object $message): void`** — fire-and-forget. Short-circuits straight to local delivery when the target is the same node (no frame on the wire). Opens a `cluster.send` Producer span for remote sends.
- **`ask(object $message, Duration $timeout): Future`** — registers a correlation slot in `TcpAskRegistry`, stamps a reply path derived from the sending node's address, sends the request frame, and returns a `Future` that resolves on reply or fails with `AskTimeoutException` after `$timeout`. Opens a `cluster.ask` Producer span. Throws `AskCapacityExceededException` when the registry is at capacity.
- **`isAlive(): bool`** — reflects whether the target node is currently `Up` in the membership view.

## Failure-detection configuration

The phi-accrual failure detector follows the Hayashibara (2004) algorithm — the same approach used by Akka, Cassandra, and Hazelcast. All five knobs map directly to the Hazelcast equivalents:

| Hazelcast property | Nexus field | Default | How to set |
|---|---|---|---|
| `heartbeat-interval-millis` | `heartbeatInterval` | 1 s | `create(heartbeatInterval: ...)` or `withHeartbeatInterval()` |
| `max.no.heartbeat.seconds` | `maxNoHeartbeat` | 10 s | `withFailureDetection(maxNoHeartbeat: ...)` |
| `phiaccrual.threshold` | `phiThreshold` | 8.0 | `create(phiThreshold: ...)` or `withPhiThreshold()` |
| `phiaccrual.sample.size` | `phiSampleSize` | 200 | `withFailureDetection(sampleSize: ...)` |
| `phiaccrual.min-std-dev-millis` | `phiMinStdDev` | 500 ms | `withFailureDetection(minStdDev: ...)` |

```php title="Failure-detection tuning" verify:lint-only
use Monadial\Nexus\Cluster\Tcp\ClusterTopology;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Runtime\Duration;

$topology = ClusterTopology::create(
    clusterName:       'production',
    self:              new NodeAddress('production', 'eu', 'orders', 'node-1'),
    bindEndpoint:      NodeEndpoint::fromString('0.0.0.0:7355'),
    advertiseEndpoint: NodeEndpoint::fromString('10.0.0.1:7355'),
    seeds:             [NodeEndpoint::fromString('10.0.0.2:7355')],
    phiThreshold:      10.0,           // raise for noisy or high-latency networks
)->withFailureDetection(
    sampleSize:     300,                             // more history, smoother phi curve
    minStdDev:      Duration::seconds(1),            // widen for high-jitter links
    maxNoHeartbeat: Duration::seconds(30),           // lenient give-up for WAN links
);
```

**Detection timeline for a hard-killed node (defaults):** TCP EOF triggers an immediate `NodeSuspected(reason=Connection)`. After `maxNoHeartbeat` (10 s) without a heartbeat arriving, the node transitions to `Down`. A graceful `ClusterNode::shutdown()` broadcasts a `Leave` frame first; the peer marks the node `Down` immediately on receipt, without waiting for the phi timeout.

See [Clustering over TCP — failure-detection tuning](../guides/clustering-over-tcp.md#failure-detection-tuning) for production guidance and the trade-offs between threshold sensitivity and false-positive risk.

## TLS

```php title="TLS wiring" verify:lint-only
use Monadial\Nexus\Cluster\Tcp\ClusterTopology;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Cluster\Tcp\TlsConfig;
use Monadial\Nexus\Cluster\NodeAddress;

$tls = new TlsConfig(
    certFile:   '/certs/node.crt',
    keyFile:    '/certs/node.key',
    caFile:     '/certs/ca.crt',    // null to skip CA verification
    verifyPeer: true,               // always true in production
);

$topology = ClusterTopology::create(
    clusterName:       'production',
    self:              new NodeAddress('production', 'eu', 'orders', 'node-1'),
    bindEndpoint:      NodeEndpoint::fromString('0.0.0.0:7355'),
    advertiseEndpoint: NodeEndpoint::fromString('10.0.0.1:7355'),
    seeds:             [NodeEndpoint::fromString('10.0.0.2:7355')],
)->withTls($tls);
```

Plaintext cluster ports must not be exposed to untrusted networks. Use TLS with a private CA and `verifyPeer: true` for any deployment beyond an isolated LAN.

## Observability

Pass an `Observability` instance to `ClusterNode::boot()` to enable full instrumentation. When omitted, a no-op implementation is used with zero overhead.

**Spans** (W3C trace-context propagated across node boundaries):

| Span | Kind | Attributes |
|---|---|---|
| `cluster.handshake` | Internal | `nexus.cluster.peer`, `nexus.cluster.handshake.outcome` (accepted / rejected) |
| `cluster.send` | Producer | `messaging.system=nexus-tcp`, `nexus.cluster.peer`, `nexus.message.type` |
| `cluster.ask` | Producer | `messaging.system=nexus-tcp`, `nexus.cluster.peer`, `nexus.message.type` |

**Metrics** (OTLP):

| Metric | Unit | Description |
|---|---|---|
| `nexus.cluster.messages.sent` | `{message}` | Remote `tell` calls dispatched |
| `nexus.cluster.messages.local_shortcircuit` | `{message}` | Self-node tells delivered locally |
| `nexus.cluster.asks.sent` | `{message}` | Remote `ask` calls registered |
| `nexus.cluster.asks.capacity_rejected` | `{message}` | Asks rejected when registry is at capacity |
| `nexus.cluster.bytes.sent` | `By` | Outbound frame bytes (histogram) |
| `nexus.cluster.frames.sent` | `{frame}` | Outbound frames |
| `nexus.cluster.handshake.rejected` | `{handshake}` | Handshakes rejected due to parse failure |

**PSR-14 events** — dispatched through `ActorSystem`'s event dispatcher:

| Event class | Properties |
|---|---|
| `NodeUp` | `$node: NodeAddress`, `$endpoint: NodeEndpoint` |
| `NodeDown` | `$node: NodeAddress` |
| `NodeSuspected` | `$node: NodeAddress`, `$reason: SuspicionReason` (Connection / Gossip / Phi) |
| `PeerConnected` | `$peer: NodeAddress`, `$endpoint: NodeEndpoint` |
| `PeerDisconnected` | `$peer: NodeAddress` |

**Leveled logging** — pass a PSR-3 `LoggerInterface` to `ClusterNode::boot()` to receive structured log entries for handshake events, peer connections, reconnect attempts, and membership transitions.

## Querying the cluster view

```php title="Cluster view" verify:lint-only
use Monadial\Nexus\Cluster\Tcp\ClusterNode;

// Synchronous — call from inside a scheduleOnce() callback (requires runtime event loop)
$view = $node->view();
$upNodes = $view->upNodes(); // list<MemberRecord>

// Asynchronous — safe from timer callbacks where view() cannot yield
$node->queryViewAsync($collectorRef); // delivers ClusterView to $collectorRef on next tick
```

## Shutdown

```php title="Graceful shutdown" verify:lint-only
// Broadcast Leave frame to all peers before closing connections
$node->shutdown();
$system->shutdown(Duration::seconds(5));
```

`ClusterNode::shutdown()` broadcasts a `Leave` frame so peers mark this node `Down` immediately, without waiting for the phi-accrual timeout. Call it before or during `ActorSystem::shutdown()`.

## See also

- [nexus-cluster](./cluster.md) — `NodeAddress`, `ClusterTransport`, and `NodeHashRing` contracts that this package implements
- [nexus-serialization-msgpack](./serialization-msgpack.md) — MessagePack codec used for all cluster wire frames
- [nexus-observability](./observability.md) — wiring the `Observability` instance
- [Clustering over TCP guide](../guides/clustering-over-tcp.md) — topology config for NAT and Kubernetes, seed discovery, failure-detection tuning, and consistency caveats
- [Two-node example](https://github.com/nexus-actors/nexus/tree/main/examples/nexus-cluster-tcp) — runnable Docker Compose demo: gossip join, tell/ask, kill/recover, graceful leave
