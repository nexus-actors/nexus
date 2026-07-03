<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Routing;

use Monadial\Nexus\Core\Actor\ActorRef;
use Symfony\Component\Messenger\Envelope;

/**
 * Resolves an inbound Messenger envelope to the Nexus actor that should
 * receive its message. Returning null marks the message unroutable; the
 * ReceiverActor then applies its UnroutablePolicy (reject or dead-letters).
 *
 * @psalm-api
 */
interface MessageRouter
{
    /**
     * @return ActorRef<object>|null
     */
    public function route(object $message, Envelope $envelope): ?ActorRef;
}
