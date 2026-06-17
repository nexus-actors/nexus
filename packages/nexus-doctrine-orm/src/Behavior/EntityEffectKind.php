<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Behavior;

/** @psalm-api */
enum EntityEffectKind
{
    case Same;
    case Persist;
    case Remove;
    case Stop;
    case Stash;
}
