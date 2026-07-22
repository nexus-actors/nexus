<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Routing;

use Symfony\Component\Messenger\Envelope;

/**
 * Decides whether an inbound envelope is permitted to be routed to a given actor
 * target. Used by StampMessageRouter to authorize producer → target routing, so a
 * producer with publish rights cannot invoke every registered target.
 *
 * Implementations must fail closed: deny when the producer identity is absent,
 * unknown, or not permitted for the requested target.
 *
 * @psalm-api
 */
interface TargetAuthorizer
{
    /**
     * @param string $targetPath the resolved target actor-path the envelope is addressed to
     * @return bool true if the envelope's producer is authorized to reach $targetPath
     */
    public function authorize(string $targetPath, Envelope $envelope): bool;
}
