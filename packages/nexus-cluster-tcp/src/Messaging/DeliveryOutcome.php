<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Messaging;

/**
 * @psalm-api
 *
 * Result of attempting to deliver an inbound cluster message to a local actor.
 */
enum DeliveryOutcome
{
    /** The message was enqueued to a resolved local actor's mailbox. */
    case Delivered;

    /** No local actor was found for the target path, or its mailbox rejected the message. */
    case Unroutable;
}
