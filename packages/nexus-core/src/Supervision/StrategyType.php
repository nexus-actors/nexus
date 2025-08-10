<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Supervision;

enum StrategyType: string
{
    case OneForOne = 'one_for_one';
    case AllForOne = 'all_for_one';
    case ExponentialBackoff = 'exponential_backoff';
}
