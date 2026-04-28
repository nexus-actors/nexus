<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Rejection;

/**
 * Raised when no route matched the request path. Maps to HTTP 404.
 */
final class RouteNotFoundRejection extends RouteRejection
{
    public function __construct(public readonly string $path)
    {
        parent::__construct('not_found', "no route matched '{$path}'", 404);
    }
}
