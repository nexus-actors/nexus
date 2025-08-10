<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Supervision;

enum Directive: string
{
    case Restart = 'restart';
    case Stop = 'stop';
    case Resume = 'resume';
    case Escalate = 'escalate';
}
