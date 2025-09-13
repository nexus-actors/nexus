<?php
declare(strict_types=1);

namespace Monadial\Nexus\Core\Supervision;

/** @psalm-api */
enum Directive: string
{
    case Restart = 'restart';
    case Stop = 'stop';
    case Resume = 'resume';
    case Escalate = 'escalate';
}
