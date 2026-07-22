<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp;

/**
 * @psalm-api
 *
 * The admission outcome of handing one frame to the outbound path — what the transport
 * did with it, reported honestly to the caller and to delivery telemetry.
 *
 * Cluster delivery is **at-most-once**. Even {@see self::Admitted} is not a delivery
 * receipt: the frame was written to the OS socket (or, for loopback, handed to the peer's
 * event loop), but TCP can still lose it on a subsequent crash or reset, and no
 * acknowledgement or retry is performed. The outcome describes *admission*, not arrival.
 *
 * The three states are mutually exclusive per frame:
 *
 *   - {@see self::Admitted} — the frame was written to a live link. Best case under
 *     at-most-once: the bytes left this process.
 *   - {@see self::Buffered} — no live link right now; the frame was queued in the peer's
 *     bounded reconnect buffer and will be flushed if the link re-establishes before the
 *     buffer overflows or the connection is closed. A buffered frame may still be lost
 *     (reconnect never succeeds, buffer overflows, or the connection closes) — it is a
 *     *pending* admission, never a guarantee.
 *   - {@see self::Dropped} — the frame was not admitted and is gone: no route to the peer,
 *     the reconnect buffer was full, the link was closed, or the socket write failed. This
 *     is the outcome that must never be miscounted as sent.
 */
enum DeliveryOutcome: string
{
    case Admitted = 'admitted';
    case Buffered = 'buffered';
    case Dropped = 'dropped';
}
