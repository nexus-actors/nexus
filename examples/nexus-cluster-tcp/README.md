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

## Local development without ext-swoole

`ext-swoole` is only required for the real-socket TCP mesh (this demo). It is declared under `suggest` — not `require` — in `nexus-cluster-tcp`'s `composer.json`, so the package's loopback and unit tests run in the plain `php` container with no Swoole extension present.

From the monorepo root:

```bash
make test-cluster-loopback   # loopback + unit tests, plain php container (no ext-swoole)
make test-cluster            # real-socket Swoole mesh tests, php-swoole container (needs ext-swoole)
```

Use `test-cluster-loopback` for fast iteration on cluster logic that does not depend on real sockets; reach for `test-cluster` (and this Docker Compose demo) only when you need the actual Swoole TCP transport.

---

## What to observe

> **Failure detection under load — read this.** Join, convergence (both nodes `UP`), the
> location-transparent `tell`, the `ask` round-trip, the **sustained** greeting loop, and the
> **kill/recover** demo all reproduce in this two-container setup: the earlier false
> `Suspect → Down` on idle long-lived links (a Swoole recv-timeout misread as a peer close)
> is fixed. What remains is a load characteristic, not visible in this gentle demo: under
> heavy message **saturation** on a single-core node the phi-accrual detector can still emit
> transient `Suspect` events that self-heal (views always reconverge, no false `Down`). The
> benchmark soak passes with the documented failure-detection tuning
> (`withFailureDetection(minStdDev: …)`). This demo runs well below that saturation regime.

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
| `CLUSTER_TLS` | _(empty)_ | Set to `1` to enable Swoole SSL on all cluster links (`ClusterTopology::withTls()`) |
| `CLUSTER_TLS_CERT` | `/certs/node.crt` | Server certificate file (only read when `CLUSTER_TLS=1`) |
| `CLUSTER_TLS_KEY` | `/certs/node.key` | Server private key file (only read when `CLUSTER_TLS=1`) |
| `CLUSTER_TLS_CA` | `/certs/ca.crt` | CA bundle used to verify peers (`verifyPeer: true`; only read when `CLUSTER_TLS=1`) |
| `CLUSTER_SECRET` | _(empty)_ | Shared HMAC handshake secret (`ClusterTopology::withAuthSecret()`). When set, unauthenticated peers are rejected before ingress. |

---

## Secure mode (TLS + shared-secret handshake)

By default this demo runs **plaintext and open** — fine for a private LAN or a
throwaway Docker network, never for anything reachable by untrusted hosts. Two
opt-in, env-gated hardening layers are wired into `bin/node.php`:

| Layer | Topology wither | What it buys you |
|---|---|---|
| **TLS** | `withTls(new TlsConfig(certFile, keyFile, caFile, verifyPeer: true))` | Transport encryption + per-node certificate identity via Swoole SSL |
| **Handshake auth** | `withAuthSecret($secret)` | HMAC-signed handshake; a reachable peer that lacks the secret is rejected before ingress even if it knows the cluster name |

Use both together for defence in depth. They are independent — you can enable
either alone.

### 1. Generate a self-signed CA + node certificate

No cert fixtures are committed. Generate a throwaway CA and a node cert
(valid for the compose service names `node-a` / `node-b`) with one openssl
invocation per file:

```bash
mkdir -p certs

# CA key + self-signed CA cert
openssl req -x509 -newkey rsa:2048 -nodes -days 365 \
  -keyout certs/ca.key -out certs/ca.crt \
  -subj "/CN=nexus-demo-ca"

# Node key + CSR (SAN covers both compose service names + loopback)
openssl req -newkey rsa:2048 -nodes \
  -keyout certs/node.key -out certs/node.csr \
  -subj "/CN=nexus-node" \
  -addext "subjectAltName=DNS:node-a,DNS:node-b,IP:127.0.0.1"

# Sign the node cert with the CA, carrying the SAN through
openssl x509 -req -in certs/node.csr -CA certs/ca.crt -CAkey certs/ca.key \
  -CAcreateserial -days 365 -out certs/node.crt \
  -copy_extensions copyall
```

Mount `certs/` into each container at `/certs` (the defaults `bin/node.php`
reads), or point `CLUSTER_TLS_CERT` / `CLUSTER_TLS_KEY` / `CLUSTER_TLS_CA` at
another path. Both nodes can share the same node cert because the SAN lists
both service names.

### 2. Run with TLS + auth enabled

```bash
docker compose run --rm \
  -e CLUSTER_TLS=1 \
  -e CLUSTER_SECRET=change-me-32-bytes-min \
  -v "$PWD/certs:/certs:ro" \
  node-a
```

Set the **same** `CLUSTER_SECRET` on every node — a mismatched or missing
secret makes the handshake fail and the peer never joins. `bin/node.php` logs
`TLS enabled for cluster links` and `Handshake authentication enabled` on boot
so you can confirm the secure path is active.

> Leaving `CLUSTER_TLS`/`CLUSTER_SECRET` unset keeps the exact plaintext
> behaviour documented in the quick-start above — the secure block is a no-op
> when the env vars are absent.

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

## Honest limitations

| Limitation | Roadmap |
|---|---|
| **No service discovery / receptionist** | Greeter path is hardcoded (`/user/greeter`) in both nodes; a receptionist registry is planned |
| **AP consistency** | A quorum floor is opt-in via `ClusterTopology::withMinimumMembers()` (a node below the floor refuses to declare peers Down); there is no automatic downing provider, so partitions otherwise continue operating independently |
| **No rejoin after Down** | A node declared Down must restart its process to re-join |
| **Single datacenter** | `NodeAddress` supports multi-DC federation but is not exercised in this two-node demo |
