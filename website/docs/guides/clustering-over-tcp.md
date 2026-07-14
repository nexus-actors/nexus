---
title: Clustering over TCP
related:
  - packages/cluster-tcp
  - packages/cluster
---

# Clustering over TCP

`nexus-cluster-tcp` provides a Swoole TCP mesh so actor systems on different machines form a cluster, discover each other through gossip, and route messages transparently across node boundaries. This guide covers topology configuration, seed discovery, TLS, failure-detection tuning, and the consistency model you are operating under.

:::note Prerequisites
- **PHP 8.5+**
- **ext-swoole ≥ 6.0** — the mesh uses Swoole coroutines. ZTS and `--enable-swoole-thread` are *not* required for the cluster itself (only for `nexus-worker-pool-swoole`).
- **Docker** — the bundled image ships PHP 8.5 + Swoole. Loopback and unit tests run in the plain `php` container without ext-swoole; real TCP transport needs Swoole.
:::

## When to reach for TCP mesh clustering

| | TCP mesh (`nexus-cluster-tcp`) | Broker-edge (`nexus-messenger`) |
|---|---|---|
| **Transport** | Direct Swoole TCP connections between nodes | External broker (Redis, AMQP, SQS, …) |
| **Latency** | Sub-millisecond round-trip on a LAN | Broker-dependent (1–100 ms typical) |
| **Throughput** | High; no broker bottleneck | Broker-bounded |
| **Failure model** | Phi-accrual + gossip; AP (partitions continue independently) | Broker availability governs; broker is the SPOF |
| **Service discovery** | Gossip convergence within 2–3 gossip rounds (a receptionist service registry is planned for a future release) | Actor paths baked into routing config or stamps |
| **Ops footprint** | No extra infra beyond the nodes themselves | Broker infra required |
| **Best for** | Low-latency actor-to-actor calls across machines, stateful sharding | Durable queuing, cross-language interop, fan-out to many consumers |

When your actors need to call each other with sub-millisecond latency and you control all nodes in the cluster, TCP mesh is the right choice. When you need durable delivery, dead-letter queuing, or interoperability with non-PHP services, reach for a broker-edge transport such as the Messenger bridge instead.

## Topology configuration

`ClusterTopology` is an immutable VO that carries everything `ClusterNode::boot()` needs:

```php title="src/Cluster/TopologyFactory.php" verify:lint-only
use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\ClusterTopology;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;

$topology = ClusterTopology::create(
    clusterName:       'production',           // must match on all nodes
    self:              new NodeAddress(
        'production',   // cluster name (matches clusterName)
        'eu-west-1',    // datacenter
        'payments',     // application / service
        'node-1',       // unique node identifier
    ),
    bindEndpoint:      NodeEndpoint::fromString('0.0.0.0:7355'),
    advertiseEndpoint: NodeEndpoint::fromString('10.0.0.1:7355'),
    seeds:             [
        NodeEndpoint::fromString('10.0.0.2:7355'),
        NodeEndpoint::fromString('10.0.0.3:7355'),
    ],
);
```

### Bind vs. advertise endpoints

`bindEndpoint` is the address the TCP server binds to locally. `advertiseEndpoint` is what peers use to connect back — they may differ when NAT or a container overlay network is involved.

| Deployment | `bindEndpoint` | `advertiseEndpoint` |
|---|---|---|
| Bare metal / VM | `0.0.0.0:7355` | `<machine-IP>:7355` |
| Docker Compose | `0.0.0.0:7355` | `<service-name>:7355` |
| Kubernetes pod | `0.0.0.0:7355` | `<pod-IP>:7355` (from `status.podIP`) |
| Kubernetes with node-port | `0.0.0.0:7355` | `<node-IP>:<node-port>` |

On bare metal where `bind` and `advertise` are the same, you can pass the same value for both.

### Seeds

Seeds are the initial contact points a new node dials when it joins. They do not need to be a fixed list of every node — once the handshake completes, the rest of the membership is learned through gossip. It is sufficient to list two or three stable nodes that are likely to be up when a new node starts.

**Single-node mode** — pass `singleNode: true` to start without any seeds (for local development or standalone testing):

```php title="Single-node topology" verify:lint-only
use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\ClusterTopology;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;

$topology = ClusterTopology::create(
    clusterName:       'development',
    self:              new NodeAddress('development', 'local', 'myapp', 'node-1'),
    bindEndpoint:      NodeEndpoint::fromString('127.0.0.1:7355'),
    advertiseEndpoint: NodeEndpoint::fromString('127.0.0.1:7355'),
    seeds:             [],
    singleNode:        true,
);
```

### Kubernetes: headless service seed discovery

In Kubernetes, use a headless service so DNS returns individual pod IPs. Each pod then resolves its peers at startup and uses the IPs as seeds:

```yaml title="k8s/headless-service.yaml"
apiVersion: v1
kind: Service
metadata:
  name: nexus-cluster
  namespace: payments
spec:
  clusterIP: None           # headless — DNS returns individual pod IPs
  selector:
    app: nexus-node
  ports:
    - name: cluster
      port: 7355
      protocol: TCP
```

In each pod, resolve the headless service DNS at startup to build the seed list:

```php title="src/Cluster/KubernetesSeeds.php" verify:lint-only
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;

/** @return list<NodeEndpoint> */
function resolveSeeds(string $headlessDns, int $port): array
{
    $ips = gethostbynamel($headlessDns);

    if ($ips === false) {
        return [];
    }

    return array_values(array_map(
        static fn(string $ip): NodeEndpoint => NodeEndpoint::fromString("{$ip}:{$port}"),
        $ips,
    ));
}

$seeds = resolveSeeds('nexus-cluster.payments.svc.cluster.local', 7355);
```

Pass `CLUSTER_SEEDS` as an env var from the pod spec (comma-separated) and parse it at startup as an alternative:

```php title="Env-based seed parsing" verify:lint-only
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;

/** @return list<NodeEndpoint> */
function seedsFromEnv(string $envVar = 'CLUSTER_SEEDS'): array
{
    $raw = getenv($envVar);

    if ($raw === false || $raw === '') {
        return [];
    }

    return array_values(array_filter(
        array_map(
            static fn(string $s): ?NodeEndpoint => ($s !== '') ? NodeEndpoint::fromString(trim($s)) : null,
            explode(',', $raw),
        ),
    ));
}
```

## TLS

Enable mutual TLS by passing a `TlsConfig` to the topology:

```php title="src/Cluster/TlsSetup.php" verify:lint-only
use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\ClusterTopology;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Cluster\Tcp\TlsConfig;

$tls = new TlsConfig(
    certFile:   '/certs/node.crt',
    keyFile:    '/certs/node.key',
    caFile:     '/certs/ca.crt',
    verifyPeer: true,
);

$topology = ClusterTopology::create(
    clusterName:       'production',
    self:              new NodeAddress('production', 'eu-west-1', 'payments', 'node-1'),
    bindEndpoint:      NodeEndpoint::fromString('0.0.0.0:7355'),
    advertiseEndpoint: NodeEndpoint::fromString('10.0.0.1:7355'),
    seeds:             [NodeEndpoint::fromString('10.0.0.2:7355')],
)->withTls($tls);
```

In Kubernetes, mount certificates from a `Secret` or use cert-manager to inject them into each pod. Set `verifyPeer: true` so each node validates its peer's certificate against the CA — this prevents a rogue process from joining the cluster.

:::tip Security baseline
Plaintext cluster ports must never be exposed outside an isolated private network. Enable TLS for any deployment that spans datacenters, clouds, or untrusted segments, and complement it with a network policy that restricts who can reach port 7355.
:::

## Shared-secret handshake authentication

By default `clusterName` is only a label — any peer that can reach the port and speak the handshake protocol is admitted. To restrict membership to nodes that hold a shared secret, set `ClusterTopology::withAuthSecret($secret)`. When set, `HandshakeAuthenticator` signs every outbound handshake with an HMAC over the secret, and `ClusterNode` rejects any inbound handshake that is unsigned or carries a bad signature before it reaches ingress — the rejection is counted as `nexus.cluster.handshake.rejected`.

```php title="Enabling shared-secret auth" verify:lint-only
use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\ClusterTopology;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;

$topology = ClusterTopology::create(
    clusterName:       'production',
    self:              new NodeAddress('production', 'eu-west-1', 'payments', 'node-1'),
    bindEndpoint:      NodeEndpoint::fromString('0.0.0.0:7355'),
    advertiseEndpoint: NodeEndpoint::fromString('10.0.0.1:7355'),
    seeds:             [NodeEndpoint::fromString('10.0.0.2:7355')],
)->withAuthSecret(getenv('NEXUS_CLUSTER_SECRET'));
```

Every node in the cluster must be configured with the same secret. Pass `null` (the default) to disable auth; an empty string is rejected. Shared-secret auth is orthogonal to TLS — auth proves a peer holds the secret, TLS encrypts the link and validates certificates. Use both on untrusted segments.

## Failure-detection tuning

The phi-accrual detector computes a suspicion level (`phi`) from the inter-arrival time distribution of heartbeats. When `phi` exceeds `phiThreshold`, the peer is suspected. After `maxNoHeartbeat` without any heartbeat, the node is declared `Down` regardless of phi.

The defaults are conservative and safe for LAN deployments:

| Parameter | Default | Effect |
|---|---|---|
| `heartbeatInterval` | 1 s | How often gossip frames are exchanged (gossip IS the heartbeat) |
| `maxNoHeartbeat` | 10 s | Give-up window; node declared `Down` if silent for this long |
| `phiThreshold` | 8.0 | Suspicion threshold; higher = more tolerant; 8–12 for production |
| `phiSampleSize` | 200 | Heartbeat history depth; more = smoother curve but slower adaptation |
| `phiMinStdDev` | 500 ms | Minimum jitter estimate; prevents a unrealistically smooth distribution |

**Adjustment guidance:**

- **LAN, low jitter** — defaults work well. Lower `phiThreshold` to 6.0 only if you need faster detection and your network is very stable.
- **WAN or cross-datacenter** — raise `maxNoHeartbeat` to 30–60 s, increase `phiMinStdDev` to 1–2 s, and raise `phiThreshold` to 10–12 to tolerate genuine network jitter (WAN links, lossy routing).
- **Fast demo / testing** — lower `maxNoHeartbeat` to 4 s to see failure detection in under 5 seconds. Do not lower `phiThreshold` below 8.0 at a 1 s heartbeat interval.

The phi detector feeds on the timestamp a heartbeat is observed at TCP-frame ingress, not when the `MembershipActor` gets around to processing it — so local scheduler contention or GC pauses on a busy reactor no longer poison the inter-arrival window. The defaults above are correct out of the box on a LAN; only widen `phiMinStdDev`/`phiThreshold` to absorb real network-level variance (WAN, lossy links), not local pauses. Validated by a 16-node soak test at default phi with zero false `Down` transitions.

```php title="Production tuning example" verify:lint-only
use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\ClusterTopology;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Runtime\Duration;

$topology = ClusterTopology::create(
    clusterName:       'production',
    self:              new NodeAddress('production', 'eu-west-1', 'payments', 'node-1'),
    bindEndpoint:      NodeEndpoint::fromString('0.0.0.0:7355'),
    advertiseEndpoint: NodeEndpoint::fromString('10.0.0.1:7355'),
    seeds:             [NodeEndpoint::fromString('10.0.0.2:7355')],
    phiThreshold:      10.0,
)->withFailureDetection(
    sampleSize:     300,
    minStdDev:      Duration::millis(500),
    maxNoHeartbeat: Duration::seconds(30),
);
```

**Failure timelines:**

- **Hard kill (crash, OOM, SIGKILL):** TCP EOF triggers immediate `NodeSuspected(reason=Connection)`. Node declared `Down` after `maxNoHeartbeat` without a heartbeat.
- **Graceful shutdown:** `ClusterNode::shutdown()` broadcasts a `Leave` frame. Peers mark the node `Down` immediately on receipt — no phi-accrual wait.
- **Network partition:** Both sides continue operating independently (AP). When the partition heals, nodes reconnect and gossip reconciles membership. Nodes that were `Down` on either side must restart their process to re-join.

## Consistency caveats

:::warning This cluster model is AP, not CP
`nexus-cluster-tcp` is an **AP system** (available under partition). Understand the implications before using it for coordinated state:

- **No quorum by default; split-brain protection is opt-in.** Out of the box, both sides of a network partition continue accepting messages independently. Any actor that holds mutable state (counters, locks, ledger balances) may diverge across partitions and have no automatic reconciliation path. Set `ClusterTopology::withMinimumMembers()` to a quorum (e.g. N/2 + 1) to bound this: a side that falls below the floor stops declaring peers `Down` and enters a degraded mode (emitting `ClusterDegraded`) instead of evicting the majority as a split-brain minority.
- **No leader election.** There is no concept of a cluster leader or primary node. All nodes are peers.
- **Gossip convergence is eventual.** Membership views converge within 2–3 gossip rounds (roughly 2–3 s at the default 1 s interval) under normal conditions, since gossip fans out to a subset of peers per round rather than broadcasting. A freshly `Up` node may not be visible to all peers immediately; a freshly `Down` node may still appear in some views for a round or two.
- **No rejoin after `Down`.** A node declared `Down` must restart its process to re-join the cluster. There is no automatic re-admission path in the current release.
- **No receptionist / service registry.** Actor paths are known through naming conventions or deployment configuration. The receptionist pattern (dynamic service lookup) is planned for a future release.

For use cases that require coordination — distributed counters, single-writer aggregates, distributed locks — combine TCP mesh with the [single-writer aggregate pattern](../core-concepts/passivation.md) (one actor per entity, routed by consistent hash) or delegate coordination to an external store.
:::

## Membership edge cases

Two behaviours are worth understanding when reasoning about how peers appear and disappear from a view:

- **Departed peers are tombstoned.** When a peer leaves gracefully (broadcasts a `Leave` frame) or its link definitively closes, the node records a tombstone for that peer's identity. Lagging gossip that still lists the departed peer is filtered against this tombstone, so a peer that has left cannot be resurrected in the view by an out-of-date gossip message from a third node. The tombstone is cleared the moment the peer re-handshakes on rejoin, so restart-and-rejoin still works — the returning node re-announces itself through the per-connection handshake preamble and is admitted normally. The tombstone set is bounded (Leave frames are unauthenticated, so an unbounded set would be a memory-exhaustion vector).

- **Hard kills are only detected directly when a direct link exists.** A hard kill (crash, OOM, `SIGKILL`) is detected immediately via TCP EOF **only on nodes that hold a direct TCP link to the killed peer**. A node that knows a peer *only through gossip* — it has never dialled that peer directly — receives no EOF when the peer dies, and detects the loss only through phi-accrual on missing heartbeats (slower, `maxNoHeartbeat`-bounded). For clusters up to roughly 50 nodes, the recommended topology is therefore a **full mesh**: every node seeds and dials every other node, so every peer holds a direct link to every other peer and hard kills are caught immediately by TCP EOF everywhere. This limitation of gossip-plus-phi without indirect probing is covered by the three-node integration test (`ClusterNodeSwooleTest::threeNodesConvergeThenSurviveOneFailing`).

## Rolling restarts and upgrades

Because there is no rejoin after `Down` (the process must restart to re-join), a rolling upgrade needs explicit sequencing rather than a blind rolling-deploy:

1. **Restart nodes one at a time.** Never stop more than one node concurrently.
2. **Stop the node gracefully** — call `ClusterNode::shutdown()` before `ActorSystem::shutdown()`. This broadcasts a `Leave` frame, and peers remove the node and mark it `Down` immediately on receipt, with no phi-accrual wait. This is faster and cleaner than letting peers detect the process disappearing via TCP EOF and `maxNoHeartbeat`.
3. **Restart the process.** It reconnects to its configured seeds and performs a fresh handshake, rejoining as a new incarnation — there is no state carried over from its previous membership.
4. **Wait for the restarted node to show `Up`** in peer views before moving on: subscribe to the PSR-14 `NodeUp` event, or poll with `ClusterNode::queryViewAsync()` (safe to call from a timer callback; `view()` requires the runtime event loop).
5. **Only then restart the next node.** Repeat until all nodes are upgraded.

Keep `ClusterTopology::withMinimumMembers()` satisfied throughout the rollout — never take enough nodes down at once that reachable membership drops below the configured quorum floor. Below the floor the cluster enters degraded mode (`ClusterDegraded`) and stops making new `Down` decisions, which is a safety net against split-brain, not a substitute for sequencing restarts one node at a time.

## Wire format and serialisation

All cluster frames are MessagePack-encoded, but two distinct encoders are used. The four control frames (handshake, handshake-ack, gossip, leave) are packed by a hand-rolled `ControlFrameCodec` (its per-frame codecs plus `MsgpackReader`), not by Valinor. The Valinor-backed `MessagePackMessageSerializer` from `nexus-serialization-msgpack` is retained only for user message *bodies*. Both encoders emit the same MessagePack maps and both decoders resolve fields by key, so a node running either encoder interoperates with a node running the other — rolling upgrades across this change are safe. The wire format is:

```
[4-byte big-endian uint32: body length][1-byte FrameType][N bytes: msgpack payload]
```

The maximum decoded frame body size defaults to 8 MiB and is tunable with `ClusterTopology::withMaxFrameSize()` — a peer that declares a larger frame is rejected before its body is buffered, bounding per-link reassembly memory. User messages are embedded in `Message` frames; handshake, handshake-ack, gossip, and leave frames use their own types. The `TypeRegistry` passed to `ClusterNode::boot()` covers both cluster wire types and user-defined message types in one shared registry.

## Reconnect backoff

When a peer connection drops, `ClusterNode` retries with exponential backoff:

```php title="Custom reconnect backoff" verify:lint-only
use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\ClusterTopology;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Runtime\Duration;

$topology = ClusterTopology::create(
    clusterName:       'production',
    self:              new NodeAddress('production', 'eu-west-1', 'payments', 'node-1'),
    bindEndpoint:      NodeEndpoint::fromString('0.0.0.0:7355'),
    advertiseEndpoint: NodeEndpoint::fromString('10.0.0.1:7355'),
    seeds:             [NodeEndpoint::fromString('10.0.0.2:7355')],
)->withReconnectBackoff(
    initialBackoff: Duration::millis(200),  // default: 100 ms
    maxBackoff:     Duration::seconds(60),  // default: 30 s
);
```

## Inbound connection limits

Inbound connections are unauthenticated by default — enable [shared-secret auth](#shared-secret-handshake-authentication) with `withAuthSecret()` to require a secret (see the trust model in the [package reference](../packages/cluster-tcp.md#security--trust-model)). Regardless of auth, `ClusterNode` bounds two things by default to blunt trivial resource-exhaustion:

- **Concurrent accepted links** are capped at `maxInboundLinks` (default 1024). A connection beyond the ceiling is closed immediately.
- **Unfinished handshakes** are timed out: an accepted link that has not sent a valid handshake within `handshakeTimeout` (default 10 s) is closed, so a peer cannot hold a socket open without identifying itself.

Both are tunable together via `withInboundLimits()`. Raise the cap for very large meshes; shorten the timeout on hostile-adjacent networks. These are resource guards, not authentication — keep the mesh on a trusted segment.

```php title="Tuning inbound limits" verify:lint-only
use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\ClusterTopology;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Runtime\Duration;

$topology = ClusterTopology::create(
    clusterName:       'production',
    self:              new NodeAddress('production', 'eu-west-1', 'payments', 'node-1'),
    bindEndpoint:      NodeEndpoint::fromString('0.0.0.0:7355'),
    advertiseEndpoint: NodeEndpoint::fromString('10.0.0.1:7355'),
    seeds:             [NodeEndpoint::fromString('10.0.0.2:7355')],
)->withInboundLimits(
    handshakeTimeout: Duration::seconds(5),  // default: 10 s
    maxInboundLinks:  4096,                   // default: 1024
);
```

## Observing the cluster

Subscribe to PSR-14 events dispatched by `ActorSystem` for operational visibility:

```php title="PSR-14 event listener" verify:lint-only
use Monadial\Nexus\Cluster\Tcp\Membership\NodeDown;
use Monadial\Nexus\Cluster\Tcp\Membership\NodeSuspected;
use Monadial\Nexus\Cluster\Tcp\Membership\NodeUp;
use Psr\EventDispatcher\ListenerProviderInterface;

// Wire into your PSR-14 event dispatcher (Symfony EventDispatcher, etc.)
// Events: NodeUp, NodeDown, NodeSuspected, PeerConnected, PeerDisconnected, ClusterDegraded
$dispatcher->addListener(NodeSuspected::class, function (NodeSuspected $event): void {
    // $event->node: NodeAddress, $event->reason: SuspicionReason (Connection/Gossip/Phi/Silence)
});

$dispatcher->addListener(NodeDown::class, function (NodeDown $event): void {
    // trigger alert, update load balancer weights, etc.
});
```

`ClusterDegraded` (`$reachableMembers: int`, `$requiredMembers: int`) fires while the node is below the `withMinimumMembers()` floor — alert on it to catch lost quorum.

Pass the PSR-14 dispatcher to `ActorSystem::create()` and it is automatically threaded to `ClusterNode::boot()` through the system.

## See also

- [nexus-cluster-tcp package](../packages/cluster-tcp.md) — API reference: `ClusterNode`, `ClusterTopology`, `ClusterRef`, observability surface
- [nexus-cluster package](../packages/cluster.md) — `NodeAddress`, `ClusterTransport`, and `NodeHashRing` contracts
- [Two-node example](https://github.com/nexus-actors/nexus/tree/main/examples/nexus-cluster-tcp) — runnable Docker Compose demo with failure detection, tell, and ask
