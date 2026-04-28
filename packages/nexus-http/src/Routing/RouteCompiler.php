<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Routing;

final readonly class RouteCompiler
{
    public static function compile(Route $tree): DispatchTrie
    {
        return new DispatchTrie($tree);
    }
}
