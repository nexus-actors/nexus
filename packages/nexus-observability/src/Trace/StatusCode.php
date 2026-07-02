<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Trace;

/** @psalm-api */
enum StatusCode: string
{
    case Unset = 'unset';
    case Ok = 'ok';
    case Error = 'error';
}
