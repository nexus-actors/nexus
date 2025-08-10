<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Mailbox;

enum EnqueueResult: string
{
    case Accepted = 'accepted';
    case Dropped = 'dropped';
    case Backpressured = 'backpressured';
}
