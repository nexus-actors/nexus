<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Messaging;

use Monadial\Nexus\Core\Actor\ActorRef;

/**
 * @psalm-api
 *
 * Ingress seam that resolves a target actor path and delivers a decoded message to it.
 * `InboxRouter` depends on this interface rather than inlining the registry lookup, so
 * the `LocalActorRegistry`-backed single-`ActorSystem` impl (C1 default) and the future
 * hash-ring → `Thread\Queue` threaded impl can be swapped in with zero rework.
 */
interface InboundDelivery
{
    /**
     * Deliver a decoded message to the actor at `$targetPath`.
     *
     * @param ActorRef<object>|null $replySender Reply-capable ref injected as the message
     *                                           sender when the inbound payload is an ask.
     */
    public function deliver(string $targetPath, object $message, ?ActorRef $replySender): DeliveryOutcome;
}
