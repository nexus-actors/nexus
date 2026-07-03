<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Monadial\Nexus\Runtime\Mailbox\EnqueueResult;
use NoDiscard;

/**
 * An ActorRef whose sends can report mailbox admission.
 *
 * tell() is fire-and-forget by contract and swallows the mailbox result.
 * Integrations that need delivery feedback to propagate backpressure to an
 * upstream source (for example, a message broker that should not be acked
 * until the message is safely enqueued) use offer() instead.
 *
 * @psalm-api
 *
 * @template T of object
 */
interface BackpressureCapable
{
    /**
     * Enqueue a message and report the mailbox admission result.
     *
     * Possible outcomes:
     * - Accepted       — message enqueued; caller may ack the upstream source.
     * - Backpressured  — bounded mailbox is full with the Backpressure overflow
     *                    strategy; the message was not enqueued. Caller should
     *                    pause and retry later.
     * - Dropped        — mailbox is closed or the overflow strategy discarded the
     *                    message; it was not enqueued and will not be delivered.
     *
     * @param T $message
     */
    #[NoDiscard]
    public function offer(object $message): EnqueueResult;
}
