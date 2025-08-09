<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

enum ActorState: string
{
    case New = 'new';
    case Starting = 'starting';
    case Running = 'running';
    case Suspended = 'suspended';
    case Stopping = 'stopping';
    case Stopped = 'stopped';
}
