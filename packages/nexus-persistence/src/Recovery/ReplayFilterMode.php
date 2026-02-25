<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Recovery;

enum ReplayFilterMode
{
    case Fail;
    case Warn;
    case RepairByDiscardOld;
    case Off;
}
