<?php

declare(strict_types=1);

namespace Monadial\Nexus\WorkerPool\Worker;

/** @psalm-api */
enum WorkerAskState
{
    case InProgress;
    case Replied;
    case Cancelled;
}
