# ADR 0007: Remote Ask Protocol Standards

## Status
Accepted

## Context
Remote `ask()` requires explicit protocol rules for:
- correlation and deduplication
- timeout and cancellation races
- retry behavior under packet loss
- bounded memory for terminal request state

Without a written standard, behavior drifts across refactors.

## Decision
The cluster remote ask protocol is standardized as follows.

### 1. Correlation and IDs
- Every remote ask request is identified by mandatory `Envelope.requestId`.
- `correlationId` and `causationId` are propagated unchanged across request/reply control frames.
- Caller-side pending map key is `requestId`.

### 2. Control messages
- `RemoteAskRequest`
- `RemoteAskReply`
- `RemoteAskCancel`
- `RemoteAskCancelled`
- `RemoteAskAck`

All are transported as envelope payloads over the existing cluster serializer/transport path.

### 3. Inbound state machine
State enum: `InProgress`, `Replied`, `Cancelled`

Transitions:
- `null -> InProgress`: first accepted request
- `InProgress -> Replied`: first accepted reply emission
- `InProgress -> Cancelled`: cancel received before reply
- `Replied` and `Cancelled` are terminal

Terminal precedence:
- First terminal state wins.
- Late replies after `Cancelled` are suppressed (must not send `RemoteAskReply`).

### 4. Dedup semantics
- Duplicate request in `InProgress`: respond with `RemoteAskAck`.
- Duplicate request in `Replied`: replay cached `RemoteAskReply`.
- Duplicate request in `Cancelled`: reply `RemoteAskCancelled`.
- Duplicate reply/cancel at caller: ignored after pending request is resolved.

### 5. Caller retry + ack
- Caller sends initial `RemoteAskRequest` immediately.
- Caller retries request up to configured max attempts until one of:
  - `RemoteAskAck` received
  - terminal outcome (reply/cancelled/timeout/local cancel)
- Retry timer is cancelled as soon as ack is observed.

### 6. Timeout and cancel
- Caller timeout fails future with `AskTimeoutException`, then sends best-effort `RemoteAskCancel`.
- Local `Future::cancel()` sends best-effort `RemoteAskCancel`.
- Remote cancel does not forcibly interrupt actor computation; protocol guarantees only terminal outcome handling and late-reply suppression.

### 7. Bounded memory requirements
Inbound terminal caches (`state/request/reply`) must be bounded by:
- TTL eviction (`INBOUND_TERMINAL_TTL_SECONDS`)
- max-entry eviction (`INBOUND_TERMINAL_MAX_ENTRIES`)

Eviction applies only to terminal entries.

## Consequences
### Positive
- Deterministic race behavior under reply/cancel/timeout overlap.
- Safer retries with explicit ack stop condition.
- Bounded terminal cache growth for long-running nodes.

### Trade-offs
- Slightly more protocol complexity.
- Actor-side work is not hard-cancelled by remote cancel in current model.
