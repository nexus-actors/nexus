<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Clock;

/**
 * @psalm-api
 *
 * Partial-order relation between two vector clocks.
 * `HappensBefore` / `HappensAfter` indicate causal precedence;
 * `Concurrent` means the events are causally independent
 * (a CRDT or last-writer-wins resolution may be needed);
 * `Equal` means the two events represent the same logical send.
 */
enum VectorClockOrdering
{
    case HappensBefore;
    case HappensAfter;
    case Concurrent;
    case Equal;
}
