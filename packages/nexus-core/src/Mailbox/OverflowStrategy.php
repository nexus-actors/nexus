<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Mailbox;

enum OverflowStrategy: string
{
    case DropNewest = 'drop_newest';
    case DropOldest = 'drop_oldest';
    case Backpressure = 'backpressure';
    case ThrowException = 'throw_exception';
}
