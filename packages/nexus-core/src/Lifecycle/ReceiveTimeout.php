<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Lifecycle;

use Monadial\Nexus\Runtime\Duration;

/**
 * Lifecycle signal delivered to an actor when no user message has arrived
 * within the duration configured via ActorContext::setReceiveTimeout().
 *
 * Handle via behavior->onSignal(...). Typical use: return Behavior::stopped()
 * to passivate. The actor's PostStop handler runs as normal — release any
 * resources there.
 *
 * @psalm-api
 * @psalm-immutable
 */
final readonly class ReceiveTimeout implements Signal
{
    /**
     * @param Duration $configured the idle timeout that triggered this signal
     */
    public function __construct(public Duration $configured) {}
}
