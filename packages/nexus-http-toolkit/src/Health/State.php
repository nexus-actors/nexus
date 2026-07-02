<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Toolkit\Health;

enum State: string
{
    case Up = 'up';
    case Degraded = 'degraded';
    case Down = 'down';
}
