# nexus-cluster-tcp

End-to-end Nexus cluster demo: two PHP processes form a real TCP cluster over **Swoole**, exchange gossip, detect failures with phi-accrual, and route messages transparently across process boundaries.

This is **a standalone Composer project** living inside the Nexus monorepo under `examples/`. It has its own `composer.json` and `compose.yaml`. Copy the folder to a standalone repo and `git init` it when the Nexus packages are published to Packagist.

---

## Prerequisites

| Requirement | Notes |
|---|---|
| PHP 8.5+ ZTS | Thread-safe build required by Swoole 6 (`--enable-swoole-thread`) |
| **ext-swoole ≥ 6.2.1** | Compiled with `--enable-swoole-thread`; provided by the `php-swoole` Docker image |
| Docker + Compose v2 | For the included `compose.yaml` |

> **Note:** The root Nexus CI image builds Swoole from source. This example reuses that image via the monorepo `docker/Dockerfile` `php-swoole` target so no separate build step is needed.

---

## What it demonstrates

| Concern | Where |
|---|---|
| **Swoole TCP mesh** | `ClusterNode::boot()` selects `SwooleMeshTransport` when `ext-swoole` is loaded with `SwooleRuntime` |
| **Gossip membership** | Both nodes exchange member records every 1 s; views converge within 2–3 gossip rounds |
| **Phi-accrual failure detection** | Heartbeat intervals feed the phi detector; `maxNoHeartbeat: 4 s` means a hard-killed node is marked Suspect → Down in ≈ 5 s |
| **Location-transparent `tell`** | `node->refFor(addr, path)->tell(new Greet(...))` — identical call whether target is local or remote |
| **Location-transparent `ask`** | `node->refFor(addr, path)->ask(new Greet(...), $timeout)->await()` — suspends the coroutine and resumes with the reply |
| **`#[MessageType]` wire names** | `Greet` → `example.greet`, `Greeted` → `example.greeted`; wire type decoupled from class name |
| **PSR-14 membership events** | `StdoutEventDispatcher` prints `NodeUp`, `NodeDown`, `NodeSuspected`, `PeerConnected`, `PeerDisconnected` |
| **PSR-3 logging** | Monolog forwarded to stdout and passed into `ActorSystem::create()` |
| **Single parameterised entrypoint** | `bin/node.php` reads role, bind, advertise, seeds from env — one binary, two roles |

---

## Quick start

```bash
# 1. Build the Swoole image (one-time; uses the monorepo Dockerfile php-swoole target)
docker compose build node-a

# 2. Install Composer dependencies
docker compose run --rm node-a composer install

# 3. Start both nodes (logs are interleaved — use separate terminals for clarity)
docker compose up

# Or tail each node separately:
docker compose up -d
docker compose logs -f node-a &
docker compose logs -f node-b &
```

---

## What to observe

> **Known limitation in this environment (verified 2026-07).** The initial join,
> membership convergence (both nodes `UP`), the location-transparent `tell`, and the
> `ask` round-trip all reproduce reliably. However, after the first exchange the
> phi-accrual detector currently emits false `Suspect → Down` transitions
> (`reason=Phi`) in the two-container Swoole setup — heartbeat liveness between
> long-lived peer connections is still being hardened in `nexus-cluster-tcp`. As a
> result the **sustained** 3-second greeting loop and the **kill/recover** failure
> demo below may not reproduce cleanly yet. The steady-state and failure sections
> describe the intended behaviour; treat them as the target once heartbeat liveness
> lands. The join + `tell` + `ask` path is fully functional today.

### Startup and join (first 3 seconds)

Both nodes boot simultaneously. Because they use **mutual seeding** (`node-a` seeds `node-b` and vice versa), whichever starts first waits with reconnect backoff until the peer appears.

**node-a** logs:
```
INFO  node-a: Starting actor system node=/cluster/demo/dc1/greet-app/node-a role=greeter
INFO  node-a: Cluster node booted role=greeter ...
INFO  node-a: Greeter actor exposed path=/user/greeter
```

**node-b** logs:
```
INFO  node-b: Starting actor system node=/cluster/demo/dc1/greet-app/node-b role=client
...
[HH:MM:SS] [TRANSPORT]  CONNECT  peer=/cluster/demo/dc1/greet-app/node-a  endpoint=node-a:7355
[HH:MM:SS] [MEMBERSHIP] UP       node=/cluster/demo/dc1/greet-app/node-a  endpoint=node-a:7355
INFO  node-b: Client actor spawned — will greet node-a every 3 seconds
```

### Tell + ask round-trips

Once the cluster converges (both nodes see 2 Up members), node-b sends greetings every 3 seconds:

**node-b** logs:
```
INFO  node-b: tell → Greet(name="World") sent to greeter
INFO  node-b: ask  ← Greeted received  message="Hello, node-b! Greetings from node-a."
```

**node-a** logs (on each receive):
```
INFO  node-a: Greet received  name=World
INFO  node-a: Greet received  name=node-b
```

The `tell` path has no reply (sender is null on node-a). The `ask` path uses `ClusterRef::ask()` which registers a correlation slot, sends the request with a `replyPath`, and the greeter's `$ctx->sender()?->tell(new Greeted(...))` routes the reply back over TCP.

### Failure detection — kill node-a

In a second terminal, while both nodes are running:

```bash
docker compose kill node-a    # hard kill: no graceful Leave, simulates a crashed node
```

Within **≈ 5 seconds** node-b logs the progression:

```
[HH:MM:SS] [TRANSPORT]  DISCNNCT peer=/cluster/demo/dc1/greet-app/node-a
[HH:MM:SS] [MEMBERSHIP] SUSPECT  node=/cluster/demo/dc1/greet-app/node-a  reason=Connection
[HH:MM:SS] [MEMBERSHIP] DOWN     node=/cluster/demo/dc1/greet-app/node-a
INFO  node-b: Waiting for cluster convergence  up_nodes=1
```

The timing: TCP EOF triggers an immediate `Suspect(reason=Connection)`. The phi-accrual detector confirms Down after `maxNoHeartbeat: 4 s` without a heartbeat arriving.

Bring node-a back:

```bash
docker compose start node-a
```

Within 2–3 gossip rounds (≈ 3 s) node-b logs:

```
[HH:MM:SS] [TRANSPORT]  CONNECT  peer=/cluster/demo/dc1/greet-app/node-a  endpoint=node-a:7355
[HH:MM:SS] [MEMBERSHIP] UP       node=/cluster/demo/dc1/greet-app/node-a  endpoint=node-a:7355
INFO  node-b: tell → Greet(name="World") sent to greeter
INFO  node-b: ask  ← Greeted received  message="Hello, node-b! Greetings from node-a."
```

### Graceful leave

Stop node-a cleanly (SIGTERM → Leave frame broadcast):

```bash
docker compose stop node-a
```

node-b logs `PeerDisconnected` immediately followed by `NodeDown` — no phi wait, because a Leave frame was received before the connection closed.

---

## Environment variables

| Variable | Default | Description |
|---|---|---|
| `CLUSTER_NAME` | `demo` | Cluster topology name. Must match across all nodes; mismatched nodes reject each other's handshake. |
| `NODE_DC` | `dc1` | Datacenter tag in `NodeAddress` |
| `NODE_APP` | `greet-app` | Application tag in `NodeAddress` |
| `NODE_ID` | `node-a` | Unique node identifier within the application |
| `BIND_HOST` | `0.0.0.0` | TCP bind interface |
| `BIND_PORT` | `7355` | TCP bind port |
| `ADVERTISE_HOST` | `127.0.0.1` | Hostname peers use to connect back (Docker service name in compose) |
| `ADVERTISE_PORT` | `7355` | Advertised TCP port |
| `SEEDS` | _(empty)_ | Comma-separated seed endpoints, e.g. `node-a:7355`. Empty → `singleNode: true` |
| `NODE_ROLE` | `greeter` | `greeter` — expose actor and serve; `client` — send greetings every 3 s |
| `GREETER_NODE` | `dc1/greet-app/node-a` | For client role: `dc/app/node` of the greeter node (cluster inferred from `CLUSTER_NAME`) |

---

## Architecture

```
node-a (greeter)                       node-b (client)
────────────────────────────           ────────────────────────────────────
ActorSystem (SwooleRuntime)            ActorSystem (SwooleRuntime)
  └─ GreeterActor at /user/greeter       └─ ClientActor
       ↑ exposed via ClusterNode               │ scheduleRepeatedly 3 s
       │                                       │
ClusterNode::boot()                    ClusterNode::boot()
  ├─ MembershipActor (gossip/phi)        ├─ MembershipActor (gossip/phi)
  └─ SwooleMeshTransport                 └─ SwooleMeshTransport
       ↑ TCP server :7355                      │ dials node-a:7355
       │                                       │
       └───────── TCP ──────────────────────────┘
                 (MessagePack frames)

                  PSR-14 events on both nodes:
                  NodeUp / NodeDown / NodeSuspected
                  PeerConnected / PeerDisconnected
                  → StdoutEventDispatcher (stdout)
```

**Tell path:** `ClusterRef::tell()` serialises the message into a `MessagePayload` frame (MessagePack) and sends it over the shared TCP connection. The greeter actor receives it via `InboxRouter → LocalDelivery`.

**Ask path:** `ClusterRef::ask()` registers a correlation ID in `TcpAskRegistry`, stamps a `replyPath` derived from node-b's address, sends the request frame, and returns a `Future`. The greeter replies via `$ctx->sender()->tell(new Greeted(...))` which routes the reply frame back; `TcpAskRegistry` resolves the future on arrival.

---

## Failure detection tuning

The compose file uses a short window to make the demo observable:

| Parameter | Value | Effect |
|---|---|---|
| `heartbeatInterval` | 1 s | Heartbeat frequency between peers |
| `maxNoHeartbeat` | 4 s | Give-up window before declaring Down (shortened for a fast, observable demo) |
| `phiThreshold` | 8.0 | Library default; lower is more sensitive but false-positive-prone at a 1 s heartbeat |
| `minStdDev` | 500 ms | Minimum heartbeat jitter estimate (library default) |

Only `maxNoHeartbeat` is shortened for the demo (default 10 s) so a hard kill is declared Down within ≈ 5 s. Phi is kept at the defaults: a lower `phiThreshold` (e.g. 4.0) is too sensitive for a 1 s heartbeat — ordinary coroutine/GC jitter crosses the threshold and produces false `Suspect → Down` flapping during healthy operation. For production, also raise `maxNoHeartbeat` (10–30 s) to tolerate transient network hiccups.

---

## Honest limitations (C1 scope)

| Limitation | Roadmap |
|---|---|
| **No service discovery / receptionist** | Greeter path is hardcoded (`/user/greeter`) in both nodes; a receptionist registry arrives in C2 |
| **AP consistency** | No quorum or split-brain protection; both partitions continue operating independently |
| **No rejoin after Down** | A node declared Down must restart its process to re-join |
| **No TLS** | `ClusterTopology::withTls(TlsConfig)` wires Swoole SSL options but is not demonstrated here |
| **Single datacenter** | `NodeAddress` supports multi-DC federation but is not exercised in this two-node demo |
