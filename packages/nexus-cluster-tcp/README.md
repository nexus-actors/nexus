# Nexus Cluster TCP

Nexus cluster TCP — Swoole TCP mesh transport, membership gossip, and phi-accrual failure detection.

## Install

```bash
composer require nexus-actors/cluster-tcp
```

## Security

The default `ClusterTopology` is **open and plaintext**: no TLS, no handshake authentication.
In that mode `clusterName` is just a label — any host that can reach the bind port completes a
handshake, joins the cluster view, and can deliver messages to exposed actors. **This default is
for a trusted private network (e.g. a single-tenant LAN or VPC) only.** Never expose a plaintext,
unauthenticated cluster port to an untrusted network.

For anything beyond a trusted LAN, enable both:

- **TLS** — `ClusterTopology::withTls(TlsConfig $config)`. Wraps peer connections in Swoole SSL;
  set `verifyPeer: true` so nodes validate each other's certificates against a CA (see the
  [clustering-over-tcp guide](https://docs.nexusactors.com/docs/guides/clustering-over-tcp) for
  certificate provisioning).
- **Handshake authentication** — `ClusterTopology::withAuthSecret(string $secret)`. Every
  handshake is signed with HMAC-SHA256 over the full identity claim and verified with a
  constant-time compare, plus a freshness window and replay-nonce guard. An unauthenticated or
  stale/replayed handshake is rejected before the peer is admitted.

### Trust model

`authSecret` is a **shared cluster-wide key**, not a per-node identity — it proves "this peer
knows the cluster secret," not "this peer is node X." Pair it with TLS client certificates if you
need per-node identity assurance. Within that model, an authenticated-but-compromised member can
still:

- Relay a forged `Leave` frame naming a *different* node, causing peers to evict that node from
  the view. Impact is bounded and self-healing — the evicted node's next heartbeat/gossip
  re-admits it via the membership merge.
- Assert an inflated incarnation for another node via gossip, temporarily pinning it `Suspect`/
  `Down` in receivers that only see the forged gossip. Bounded to the reachable subset of the
  mesh; the quorum floor (`withMinimumMembers`) limits mass-eviction blast radius.

These are the same accepted tradeoff as other gossip-based membership systems (Serf, Consul): a
compromised, authenticated member can disrupt membership state for other nodes, but cannot forge
messages *as* another node (the HMAC binds the full identity claim) and cannot achieve durable,
unbounded damage. Full Byzantine fault tolerance (provable resistance to an arbitrarily malicious
authenticated member) is out of scope for v1.

## Scaling

`nexus-cluster-tcp` is a **full-mesh** topology: every node dials every other node, so connections
and per-node resource usage grow as O(N²) cluster-wide (~2N file descriptors and ~2N recv
coroutines per node, one outbound + one inbound link per peer).

- **Validated:** a 16-node soak test sustained ~738k msg/s aggregate throughput with zero false
  `Down` transitions at default failure-detection settings.
- **Expected comfortable range:** up to ~50 nodes — full-list gossip and O(N²) connections stay
  cheap at this scale.
- **Past ~100 nodes:** full-member-list gossip and O(N²) connection/coroutine counts become a
  real cost; delta/scuttlebutt-style gossip (planned, not yet implemented) is advisable before
  scaling further.
- **Deployment shape:** prefer process-per-core (one Swoole reactor per OS process) over
  many-nodes-per-process, so each reactor's fd/coroutine budget stays independent and predictable.

See the [cluster-tcp benchmarks guide](https://docs.nexusactors.com/docs/guides/cluster-tcp-benchmarks)
for methodology and full results.

## Documentation

This is a read-only subtree split of [nexus-actors/nexus](https://github.com/nexus-actors/nexus).

Please refer to the main repository for documentation, issues, and pull requests.
