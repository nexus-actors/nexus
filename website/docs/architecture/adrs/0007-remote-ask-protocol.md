---
title: "ADR 0007: Remote Ask Protocol Standards"
sidebar_position: 7
related:
  - core-concepts/ask-pattern
  - architecture/adrs/0008-worker-pool-cluster-separation
  - scaling/overview
---

# ADR 0007: Remote Ask Protocol Standards

## Status

Accepted

## Context

Remote `ask()` requires explicit protocol rules for correlation and deduplication, timeout and cancellation races, retry behavior under packet loss, and bounded memory for terminal request state. Without a written standard, behavior drifts across refactors.

## Decision

The worker pool ask protocol is standardized as follows.

### 1. Correlation and IDs

- Every remote ask request is identified by mandatory `Envelope.requestId`.
- `correlationId` and `causationId` are propagated unchanged across request/reply control frames.
- Caller-side pending map key is `requestId`.

### 2. Control messages

- `WorkerAskRequest`
- `WorkerAskReply`
- `WorkerAskCancel`
- `WorkerAskCancelled`
- `WorkerAskAck`

All are transported as envelope payloads via `WorkerTransport` (no serializer — objects pass directly).

### 3. Inbound state machine

State enum: `InProgress`, `Replied`, `Cancelled`

Transitions:

- `null → InProgress`: first accepted request
- `InProgress → Replied`: first accepted reply emission
- `InProgress → Cancelled`: cancel received before reply
- `Replied` and `Cancelled` are terminal

Terminal precedence:

- First terminal state wins.
- Late replies after `Cancelled` are suppressed (must not send `WorkerAskReply`).

### 4. Dedup semantics

- Duplicate request in `InProgress`: respond with `WorkerAskAck`.
- Duplicate request in `Replied`: replay cached `WorkerAskReply`.
- Duplicate request in `Cancelled`: reply `WorkerAskCancelled`.
- Duplicate reply/cancel at caller: ignored after pending request is resolved.

### 5. Caller retry + ack

- Caller sends initial `WorkerAskRequest` immediately.
- Caller retries request up to configured max attempts until one of: `WorkerAskAck` received, or a terminal outcome (reply/cancelled/timeout/local cancel).
- Retry timer is cancelled as soon as ack is observed.

### 6. Timeout and cancel

- Caller timeout fails future with `AskTimeoutException`, then sends best-effort `WorkerAskCancel`.
- Local `Future::cancel()` sends best-effort `WorkerAskCancel`.
- Remote cancel does not forcibly interrupt actor computation; the protocol guarantees only terminal outcome handling and late-reply suppression.

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

- Slightly more protocol complexity than a simple fire-and-forget ask.
- Actor-side work is not hard-cancelled by remote cancel in the current model.
