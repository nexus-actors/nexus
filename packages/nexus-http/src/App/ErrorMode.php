<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\App;

/** @psalm-api */
enum ErrorMode
{
    case Production;
    case Development;
}
