<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\KernelPool\Message;

use Monadial\Nexus\Core\Actor\ActorRef;

/**
 * Sent by KernelActor to its parent (KernelPoolActor) after finishing a request.
 * Signals that this kernel is idle and ready to accept the next dispatch.
 */
readonly class KernelReady
{
    /**
     * @param ActorRef<object> $ref The kernel actor that just became idle.
     */
    public function __construct(public ActorRef $ref) {}
}
