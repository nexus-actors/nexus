<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Session;

enum SessionHandlerMode
{
    case Database;
    case Redis;
}
