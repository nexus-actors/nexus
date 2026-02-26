<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Mailbox;

/** @psalm-api */
enum EnqueueResult: string
{
    case Accepted = 'accepted';
    case Dropped = 'dropped';
    case Backpressured = 'backpressured';
}
