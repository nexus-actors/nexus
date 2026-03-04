<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Shutdown;

enum ShutdownTimeoutBehavior
{
    case ForceWithWarning;
    case ThrowException;
}
