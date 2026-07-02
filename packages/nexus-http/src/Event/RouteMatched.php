<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Event;

use Monadial\Nexus\Http\Routing\Route;
use Psr\Http\Message\ServerRequestInterface;

/** @psalm-api */
final readonly class RouteMatched
{
    /** @param array<string, string> $pathParams */
    public function __construct(public ServerRequestInterface $request, public Route $route, public array $pathParams) {}
}
