<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Routing;

use Closure;
use Monadial\Nexus\Http\RequestCtx;
use Psr\Http\Message\ResponseInterface;

/**
 * Composable HTTP route: a function from RequestCtx to ?ResponseInterface.
 *
 * Returning null signals rejection (try a sibling in concat / fall through to 404).
 * Returning a ResponseInterface is a completion.
 */
final readonly class Route
{
    /** @param Closure(RequestCtx): ?ResponseInterface $run */
    public function __construct(public Closure $run) {}
}
