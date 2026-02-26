<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Remote;

/**
 * Internal lifecycle state for inbound remote ask requests.
 */
enum RemoteAskState
{
    case InProgress;
    case Replied;
    case Cancelled;
}
