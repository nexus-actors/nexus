<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Exception;

/** @psalm-api */
final class RouteNotFoundException extends HttpException
{
    public function __construct(string $method, string $path)
    {
        parent::__construct(404, "No route for {$method} {$path}");
    }
}
