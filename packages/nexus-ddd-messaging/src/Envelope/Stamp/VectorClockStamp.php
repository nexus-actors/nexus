<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Envelope\Stamp;

use Monadial\Nexus\Ddd\Messaging\Clock\VectorClock;
use Monadial\Nexus\Ddd\Messaging\Envelope\Stamp;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Carries the message's Lamport-Mattern vector clock when the bus runs
 * in a distributed setting. Single-process apps don't attach this
 * stamp; vector-clock predicates only have meaningful answers when
 * multiple nodes participate.
 *
 * Distributed bus implementations attach this stamp at the send seam
 * (after `MessageMetadata::root()` or `forCausedMessage()`) and
 * re-attach a merged-and-ticked clock at the receive seam:
 *
 *     $localClock = $localClock->merge($incomingStamp->clock)->tick($localNode);
 *     $envelope = $envelope->with(new VectorClockStamp($localClock));
 */
final readonly class VectorClockStamp implements Stamp
{
    public function __construct(public VectorClock $clock) {}
}
