<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

/** @psalm-api */
enum BehaviorTag: string
{
    case Receive = 'receive';
    case WithState = 'with_state';
    case Setup = 'setup';
    case Same = 'same';
    case Stopped = 'stopped';
    case Unhandled = 'unhandled';
    case Empty = 'empty';
}
