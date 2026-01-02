<?php
declare(strict_types=1);

namespace Monadial\Nexus\Persistence\State;

enum DurableEffectType
{
    case Persist;
    case None;
    case Unhandled;
    case Stash;
    case Stop;
    case Reply;
}
