<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Monadial\Nexus\Runtime\Mailbox\EnqueueResult;

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
     * Returns Dropped when the mailbox is already closed.
     *
     * @param T $message
     */
    public function offer(object $message): EnqueueResult;
}
