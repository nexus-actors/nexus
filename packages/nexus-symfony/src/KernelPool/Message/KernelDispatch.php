<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\KernelPool\Message;

use Monadial\Nexus\Core\Actor\ActorRef;
use Symfony\Component\HttpFoundation\Request;

/**
 * Sent by KernelPoolActor to an idle KernelActor.
 * The kernel processes the request and replies KernelResponse to $replyTo.
 */
readonly class KernelDispatch
{
    /**
     * @param ActorRef<object> $replyTo The future-slot ref obtained from the original ask() sender.
     */
    public function __construct(
        public Request $request,
        public ActorRef $replyTo,
    ) {}
}
