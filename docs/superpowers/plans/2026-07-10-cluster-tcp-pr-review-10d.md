# feat/cluster-tcp — comprehensive 10-dimension PR review (2026-07-10)

Branch HEAD `6c343f2b` vs base `main` (`da8ec590`), 182 commits / ~46k lines.
Six parallel reviewers over ten dimensions. Detailed per-dimension reports live in the
session scratchpad (`prrev-*.md`); this document is the consolidated verdict of record.

## Verdict matrix

| # | Dimension | Verdict | Critical/High | Notable |
|---|-----------|---------|---------------|---------|
| 1 | Documentation | approve-with-nits | 0 | 1 Important: stale phi-tuning advice |
| 2 | Landing page | approve-with-nits | 0 | prior fixes regression-verified |
| 3 | Examples | **request-changes** | **1 High** | ProduceCommand TypeError |
| 4 | Algorithm | approve-with-nits | 0 | 7 algorithms verified sound |
| 5 | Logic | approve-with-nits | 0 | 1 minor (handshake clock, by-design) |
| 6 | DSL / API surface | approve-with-nits | 0 | isAlive() stub (tracked) |
| 7 | Scalability | approve-with-nits | 0 | limits inherent, need documenting |
| 8 | Design | approve-with-nits | 0 | pure-service/actor-shell praised |
| 9 | Architecture | approve-with-nits | 0 | contracts genuinely transport-agnostic |
| 10 | Security | approve-with-nits | 0 | 3 Medium, all doc/hardening |

**Overall: request-changes on ONE item (examples High); everything else approve-with-nits.**
No correctness, security, or architecture blocker anywhere in the 10 dimensions.

## Ranked findings — fix before merge

1. **HIGH (examples)** `examples/nexus-messenger-redis/bin/console:55-58,108` — passes a
   `NexusMessengerSerializer` (Symfony `SerializerInterface`) into `ProduceCommand`, which
   requires Nexus's own `MessageSerializer` → genuine `TypeError` on the documented
   `nexus:messenger:produce` command. Reviewer verified empirically.
2. **IMPORTANT (docs)** `website/docs/guides/clustering-over-tcp.md:203-204` +
   `website/docs/packages/cluster-tcp.md:129-134` — still recommend widening `phiMinStdDev`
   to 1–2 s for "coroutine or GC pauses"; that failure mode was eliminated on this branch by
   the phi-ingress-timestamp fix. Advice is stale and conflates fixed local jitter with
   genuine network jitter. Rewrite: default phi is correct locally; widen only for real
   network variance.
3. **MEDIUM (examples)** `examples/nexus-cluster-tcp/bin/node.php` — the `exit(1)` calls
   added for bad `NODE_ROLE`/`GREETER_NODE` run inside a Swoole coroutine → uncaught
   `Swoole\ExitException` (verified empirically). No hang, but noisy/unidiomatic. Replace
   with a clean shutdown path.
4. **MEDIUM (security M1)** `packages/nexus-cluster-tcp/README.md` — 15-line README has no
   security section; the plaintext/no-auth "trusted networks only" warnings live only in
   class docblocks an operator never sees. Add a Security section (TLS, authSecret,
   plaintext warning) and fold in the compromised-member tradeoffs (M2 Leave-relay
   spoofability, M3 forged-incarnation Down-pinning) as documented limits.
5. **MEDIUM (scalability S1)** same README — no scaling guidance. Add the practical-limits
   note: full mesh O(N²) links (~2N fds + ~2N recv coroutines per node); validated at N=16,
   expected fine to ~50, delta-gossip advisable past ~100; process-per-core deployment shape.

## Tracked follow-ups (non-blocking, unchanged from triage)

- `ClusterRef::isAlive()` stub → wire to ClusterView (docblock-tracked "C1.6b").
- Security M2/M3 code-level hardening (Leave sender binding, incarnation clamp) — document
  now (item 4), harden in a dedicated membership pass with soak validation.
- Handshake detector feed on processing-time (algorithm minor, by-design; bounded).
- Delta-gossip, Approach-B control lane, ClusterNode god-class split, perf minors
  (FrameCodec substr, phi array_shift), I3 latent suspectSince trap.

## Verified strengths (across reviewers)

- All seven core algorithms sound: phi math (direct tail computation), SWIM
  incarnation/refutation, join-semilattice merge, gossip dedup, quorum floor
  (level-triggered, no wedge), ask correlation, frame codec boundaries.
- Reworked link lifecycle (non-closing re-handshake replace, Leave-only eviction) verified
  correct AND DoS-bounded by `maxInboundLinks`.
- Every remote-input boundary type-checked; no arbitrary-class deserialization path; HMAC
  handshake sound incl. non-gameable nonce eviction; DoS caps correctly wired end-to-end.
- Memory bounded everywhere (phi window, processedLeaves FIFO, time-evicted nonces).
- `nexus-cluster` contracts genuinely transport-agnostic (QUIC impl could slot in);
  `MeshTransport` seam proven by loopback; failure-domain escalation clean/one-directional.
- Docs unusually accurate: ~14 API/metric spot-checks all correct; prior-round fixes held.
- Empirical validation: 16/16 mesh soak PASS at true default phi, ~738k msg/s, down=0.

## Merge recommendation

Fix items 1–5 (all small: one example wiring bug, one example exit path, three doc edits),
re-run the example + affected suites, then merge. No re-soak needed — none of the five
touch cluster-tcp runtime code.
