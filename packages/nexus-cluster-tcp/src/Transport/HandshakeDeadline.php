<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Transport;

/**
 * @psalm-api
 *
 * The HARD Slowloris deadline for an accepted-inbound
 * {@see \Monadial\Nexus\Cluster\Tcp\Connection\InboundLinkActor}: self-scheduled once (via
 * `ActorContext::scheduleOnce`) when the actor starts, delivered when `handshakeTimeout` elapses.
 * Unidentified handles it by closing the link and stopping; identification cancels the timer, and
 * a stale delivery that already fired before the cancel is ignored by Identified.
 *
 * A dedicated self-message — NOT `setReceiveTimeout`/`ReceiveTimeout` — because the receive-timeout
 * mechanism resets on EVERY user message: an unauthenticated peer trickling junk non-Handshake
 * frames at intervals just under the deadline would defer a receive-timeout forever (each junk
 * frame is silently dropped by Unidentified per C2, so the bounded mailbox never fills and the
 * acceptor's Dropped-enqueue flood bound never trips either). A `scheduleOnce` deadline is immune:
 * intervening traffic does not reset it.
 *
 * This is the graceful PRIMARY layer only: because it travels through the actor's bounded
 * DropNewest mailbox, a peer that fills the mailbox EXACTLY to capacity and then goes silent
 * starves it — the deadline self-tell is dropped on arrival, and a silent peer never triggers
 * another `offer()`-Dropped close. {@see InboundLinkAcceptor} therefore also arms an OUT-OF-BAND
 * `scheduleOnce` backstop (handshake timeout + grace) that closes the raw link directly, disarmed
 * at identification via the per-link `$onIdentified` seam — together the two layers match the
 * pre-actorization acceptor-owned timer's immunity exactly.
 *
 * Lives in `Transport/` beside {@see LinkFrame}/{@see LinkClosedNotice}: the dependency boundary
 * allows `Connection/` → `Transport/`, not the reverse, and grouping the per-link actor's wire-side
 * protocol messages in one place keeps that direction obvious.
 */
final readonly class HandshakeDeadline {}
