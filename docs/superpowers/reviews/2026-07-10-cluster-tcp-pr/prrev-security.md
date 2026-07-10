# Security Review — feat/cluster-tcp (nexus-cluster-tcp @ 6c343f2b)

**Verdict: approve-with-nits**

The transport is well-hardened for a v1 clustering layer. Every remote-input trust boundary I
traced is type-checked before use, the deserialization paths cannot be driven to instantiate
arbitrary classes, DoS caps are present and correctly wired, and the HMAC handshake construction is
sound (canonical JSON, `hash_equals`, freshness window, replay guard). The findings below are
either documentation gaps or bounded/self-healing membership-layer weaknesses that are the same
accepted tradeoff as Serf/Consul (compromised member ⇒ shared secret leaks). None block merge.

Counts: Critical 0 · High 0 · Medium 3 · Low 3

---

## Findings

### M1 — No security section in the package README (no-auth-open default is not loudly documented)
**Severity: Medium** · `packages/nexus-cluster-tcp/README.md` (15-line stub)

The default topology runs **unauthenticated and plaintext**: `ClusterTopology::create(...)` sets
`authSecret: null` and `tls: null` (ClusterTopology.php:124-125). In that mode `clusterName` is only
a label — any host that can reach the bind port completes a handshake, joins the view, delivers
messages to exposed actors, and gossips forged membership. This "trusted-network-only" posture *is*
an acceptable tradeoff, **but only if loudly documented**, and it is not: the README has no security
section at all. The warnings live scattered in class docblocks (`TlsConfig`, `HandshakeAuthenticator`,
`ClusterTopology::withAuthSecret`) that an operator wiring a topology never sees.
**Fix:** add a "Security" section to the README stating: default is open+plaintext = trusted LAN
only; enable `withAuthSecret()` + `withTls(verifyPeer:true)` for anything else; secret is a
shared cluster key (member-level, not per-node identity — pair with TLS client certs for that).

### M2 — Leave frames are spoofable: a peer can forge a Leave for a *different* node
**Severity: Medium** · `ClusterNode::processLeaveFrame` (ClusterNode.php:943-991)

`LeavePayload->node` is a self-declared path-prefix and is **never checked against the sending
peer's identity** (`$senderAddr`). The only guard is `leavingAddr !== self`. So peer A can send a
Leave naming peer B; the receiver tells the membership actor `LeaveReceived(B)` →
`applyLeave` removes B from the view (`withoutNode` + `NodeDown`) and relays the forged frame to all
other accepted peers, evicting B mesh-wide. Leave frames are explicitly unauthenticated (even the
`MAX_PROCESSED_LEAVES` cap comment at ClusterNode.php:119 acknowledges "Leave frames are
unauthenticated"). Impact is **bounded and self-healing**: B's next gossip/liveness re-adds it via
`mergeView`/`recordLiveness`. Under auth this requires a compromised member (accepted); under no-auth
it is any network attacker (folds into M1). **Fix:** reject when `leavingAddr !== senderAddr`
(a node may only announce *its own* departure), and document that Leave authenticity relies on
transport auth. At minimum, document the spoof in the method docblock.

### M3 — Forged high incarnation in gossip can pin a healthy peer Down without it learning to refute
**Severity: Medium** · `MembershipService::gossipToView` / `ClusterView::merge` (MembershipService.php:523-562, ClusterView.php:120)

`incarnation` and `status` arrive from the wire as unbounded ints (Valinor validates *type* only,
not range). `merge()` is last-writer-wins by incarnation. A malicious peer A can gossip
`{B, status:Down, incarnation: PHP_INT_MAX}` to C. C adopts it and evicts B. B's self-refutation
(`peerAssertedSelfSuspicion` → `applyRejoin(max(self, asserted)+1)`, MembershipService.php:168-187)
only fires **if B receives the forged gossip** — A can send it only to C, so B never refutes, and
even if it did, `INT_MAX+1` overflows to float and breaks incarnation monotonicity. Destabilizes the
mesh (targeted eviction). Same compromised-member tradeoff under auth; any attacker under no-auth.
The quorum floor (`withMinimumMembers`) partially mitigates mass-eviction but not a single targeted
node. **Fix:** clamp/reject implausible incarnations (e.g. reject `incarnation` beyond a sane bound
or more than K above the receiver's known value for that node); document gossip-forgery reliance on
auth. Accept as documented tradeoff for v1 if clamping is deferred.

### L1 — `applyGossip` self-refutation floor can overflow to float on a maxed incarnation
**Severity: Low** · MembershipService.php:172-178

`max($selfIncarnation, $suspectedAt)` then `+1` in `applyRejoin`; with `$suspectedAt = PHP_INT_MAX`
the bump silently becomes a float, corrupting the int-typed incarnation contract. Sub-issue of M3;
clamping the wire incarnation fixes both.

### L2 — Re-handshake replace holds old inbound links open until their own EOF
**Severity: Low** · `ClusterNode::wireInboundLink` (ClusterNode.php:611-618)

A re-handshake replaces the `acceptedLinks[$prefix]` slot **without closing the prior link** (a
deliberate anti-reconnect-storm choice). A single authenticated peer re-handshaking repeatedly leaves
N orphaned links open. **This is bounded** — `inboundLinks` is capped by `maxInboundLinks` (checked at
ClusterNode.php:567 before any link is admitted), and orphans EOF when the peer drops them — so
aggregate live links per node cannot exceed the cap. Acceptable; note in the docblock that a single
peer can hold up to `maxInboundLinks` slots (no per-peer sub-cap), so `maxInboundLinks` should exceed
expected legitimate peer count comfortably.

### L3 — `parseHandshakeFrame` defaults missing NodeAddress fields to `'unknown'`
**Severity: Low** · ClusterNode.php:850-855

Missing `node[*]` fields become the literal `'unknown'`, so a peer omitting fields is admitted under
`/cluster/unknown/unknown/...`. Harmless when auth is on (the MAC covers the node map with the same
`?? ''` defaults, so a signed handshake can't fabricate mismatched fields), and cosmetic under
no-auth. Consider rejecting handshakes with an incomplete node map for clarity.

---

## Protections verified (hold under the threat model)

- **HMAC handshake** — SHA-256 over canonical ordered JSON (delimiter-injection-proof, every field a
  distinct encoded value incl. the wire node map); `hash_equals` constant-time compare; freshness
  window (`abs(now-issuedAt) > window`); MAC binds the full identity claim incl. `advertise` and
  `node`, so an authenticated peer **cannot** impersonate a different node address without a valid
  signature for it. (HandshakeAuthenticator.php)
- **Replay guard** — bounded seen-nonce set; eviction drops only nonces already aged *past* the
  freshness window (so an evicted nonce can no longer pass the freshness check anyway) — the eviction
  cannot be gamed to re-enable a replay of a still-fresh handshake. Verify happens *after* MAC check,
  so an attacker can't pollute the set with unsigned nonces.
- **Data-path gate before ingress** — cluster-name/protocol/auth all checked *synchronously in
  `parseHandshakeFrame` before any `FrameIngress` is wired* (ClusterNode.php:822-848), closing the
  async membership-admission gap.
- **Deserialization trust boundary** — handshake/gossip/leave use **hardcoded literal** type strings
  (`'cluster.handshake'` etc.), never remote-controlled. User-message bodies (`ClusterMessageCodec::decode`)
  gate on `registry->classForName($type) === null` *before* deserialize, blocking the serializer's
  literal-class-name fallback (MessagePackMessageSerializer.php:91) — no arbitrary class instantiation,
  no `unserialize()` reachable from the wire. `MessagePayloadCodec::unpack` type-checks every field
  (targetPath/messageType/body string, correlationId/replyPath string-or-null, trace string→string).
  Valinor enforces the GossipPayload int shape; malformed members throw → dropped + counted.
- **replyPath confused-deputy guard** — `isValidAskReplyPath` requires the path start with
  `$origin->toPathPrefix() . '/temp/remote-ask-'`; we only ever reply to the delivering peer, so a
  forged replyPath aimed elsewhere is rejected (InboxRouter.php:172-179).
- **targetPath routing** — bounded to `expose()`d `LocalActorRef`s via `LocalActorRegistry`; a
  registry miss is `Unroutable` (dropped, never nacked). No path traversal reaches unexposed actors.
- **DoS caps** — frame-size bound rejected at the 4-byte prefix before buffering (FrameCodec.php:79);
  reassembly buffer capped at `maxFrameSize + RECV_CHUNK` (SwoolePeerLink.php:214); `PENDING_FRAME_LIMIT`
  (1024) bounds pre-handshake flood; `handshakeTimeout` slowloris guard; `maxInboundLinks` concurrency
  cap; `TcpAskRegistry` `maxPending` (10k) + `failAllForNode` on peer-down; `MAX_PROCESSED_LEAVES` (10k
  FIFO). Gossip relay is bounded (Leave dedup via `processedLeaves`; gossip picks min(3, candidates)).
- **TLS** — `verifyPeer` ⇒ `ssl_verify_peer=true` + `ssl_allow_self_signed=false` (self-signed
  rejected); client side binds `ssl_host_name` to the dialed host so the cert must match the host, not
  merely chain to the CA. No silent downgrade path — SSL mode is fixed at transport construction from
  `TlsConfig` presence.
- **Secret handling** — `authSecret`/`secret` never logged, never in exception messages, never a span
  attribute; handshake rejection logs `advertise`/`clusterName`/`node` only, never `mac`/`nonce`.
