---
title: Clustering over TCP
related:
  - packages/cluster-tcp
  - packages/cluster
  - packages/messenger
  - packages/serialization-msgpack
---

# Clustering over TCP

`nexus-cluster-tcp` provides a Swoole TCP mesh so actor systems on different machines form a cluster, discover each other through gossip, and route messages transparently across node boundaries. This guide covers topology configuration, seed discovery, TLS, failure-detection tuning, and the consistency model you are operating under.

## When to reach for TCP mesh clustering

| | TCP mesh (`nexus-cluster-tcp`) | Broker-edge (`nexus-messenger`) |
|---|---|---|
| **Transport** | Direct Swoole TCP connections between nodes | External broker (Redis, AMQP, SQS, …) |
| **Latency** | Sub-millisecond round-trip on a LAN | Broker-dependent (1–100 ms typical) |
| **Throughput** | High; no broker bottleneck | Broker-bounded |
| **Failure model** | Phi-accrual + gossip; AP (partitions continue independently) | Broker availability governs; broker is the SPOF |
| **Service discovery** | Gossip convergence within ~1 gossip interval (C2 receptionist planned) | Actor paths baked into routing config or stamps |
| **Ops footprint** | No extra infra beyond the nodes themselves | Broker infra required |
| **Best for** | Low-latency actor-to-actor calls across machines, stateful sharding | Durable queuing, cross-language interop, fan-out to many consumers |

When your actors need to call each other with sub-millisecond latency and you control all nodes in the cluster, TCP mesh is the right choice. When you need durable delivery, dead-letter queuing, or interoperability with non-PHP services, reach for the [Messenger bridge](./messenger-bridge.md) instead.

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
- **WAN or cross-datacenter** — raise `maxNoHeartbeat` to 30–60 s, increase `phiMinStdDev` to 1–2 s, and raise `phiThreshold` to 10–12 to tolerate routing variance.
- **Fast demo / testing** — lower `maxNoHeartbeat` to 4 s to see failure detection in under 5 seconds. Do not lower `phiThreshold` below 8.0 at a 1 s heartbeat interval — ordinary coroutine or GC pauses cross the threshold and produce false `Suspect → Down` flapping.

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

- **No quorum, no split-brain protection.** Both sides of a network partition continue accepting messages independently. Any actor that holds mutable state (counters, locks, ledger balances) may diverge across partitions and have no automatic reconciliation path.
- **No leader election.** There is no concept of a cluster leader or primary node. All nodes are peers.
- **Gossip convergence is eventual.** Membership views converge within ~1 gossip interval (1 s by default) under normal conditions. A freshly `Up` node may not be visible to all peers immediately; a freshly `Down` node may still appear in some views for one interval.
- **No rejoin after `Down`.** A node declared `Down` must restart its process to re-join the cluster. There is no automatic re-admission path in C1.
- **No receptionist / service registry.** Actor paths are known through naming conventions or deployment configuration. The receptionist pattern (dynamic service lookup) is planned for C2.

For use cases that require coordination — distributed counters, single-writer aggregates, distributed locks — combine TCP mesh with the [single-writer aggregate pattern](../core-concepts/passivation.md) (one actor per entity, routed by consistent hash) or delegate coordination to an external store.
:::

## Wire format and serialisation

All cluster frames use MessagePack encoding (via `nexus-serialization-msgpack`). The wire format is:

```
[4-byte big-endian uint32: body length][1-byte FrameType][N bytes: msgpack payload]
```

Maximum frame size is 8 MB. User messages are embedded in `Message` frames; handshake, gossip, and leave frames use their own types. The `TypeRegistry` passed to `ClusterNode::boot()` covers both cluster wire types and user-defined message types in one shared registry.

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

## Observing the cluster

Subscribe to PSR-14 events dispatched by `ActorSystem` for operational visibility:

```php title="PSR-14 event listener" verify:lint-only
use Monadial\Nexus\Cluster\Tcp\Membership\NodeDown;
use Monadial\Nexus\Cluster\Tcp\Membership\NodeSuspected;
use Monadial\Nexus\Cluster\Tcp\Membership\NodeUp;
use Psr\EventDispatcher\ListenerProviderInterface;

// Wire into your PSR-14 event dispatcher (Symfony EventDispatcher, etc.)
// Events: NodeUp, NodeDown, NodeSuspected, PeerConnected, PeerDisconnected
$dispatcher->addListener(NodeSuspected::class, function (NodeSuspected $event): void {
    // $event->node: NodeAddress, $event->reason: SuspicionReason (Connection/Gossip/Phi)
});

$dispatcher->addListener(NodeDown::class, function (NodeDown $event): void {
    // trigger alert, update load balancer weights, etc.
});
```

Pass the PSR-14 dispatcher to `ActorSystem::create()` and it is automatically threaded to `ClusterNode::boot()` through the system.

## See also

- [nexus-cluster-tcp package](../packages/cluster-tcp.md) — API reference: `ClusterNode`, `ClusterTopology`, `ClusterRef`, observability surface
- [nexus-cluster package](../packages/cluster.md) — `NodeAddress`, `ClusterTransport`, and `NodeHashRing` contracts
- [nexus-messenger bridge](./messenger-bridge.md) — broker-edge alternative for durable queuing and cross-language interop
- [Two-node example](https://github.com/nexus-actors/nexus/tree/main/examples/nexus-cluster-tcp) — runnable Docker Compose demo with failure detection, tell, and ask
