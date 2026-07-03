<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Consumer;

/**
 * What the ReceiverActor does with an inbound message no router resolves.
 *
 * @psalm-api
 */
enum UnroutablePolicy
{
    case DeadLetters;
    case Reject;
}
