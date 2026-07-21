<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Exception;

use Monadial\Nexus\Core\Exception\NexusException;

/**
 * @psalm-api
 *
 * Thrown at compile time when a route's handler class declares an
 * authorization requirement (an attribute implementing
 * AuthorizationRequirement, e.g. #[RequiresAuth]) but the route has no
 * middleware implementing AuthorizationEnforcer. Serving such a route would
 * silently skip the declared protection, so compilation fails closed instead.
 */
final class UnprotectedRouteException extends NexusException
{
    public function __construct(string $method, string $path, string $handlerClass, string $requirement)
    {
        parent::__construct(
            "{$method} {$path} handler {$handlerClass} declares #[{$requirement}] but the route has no "
            . 'authorization middleware. Add ->middleware(AuthorizationMiddleware::class) to the route '
            . '(WebSocket routes: wsMiddleware() or the ws()/channel() middleware list), or remove the attribute.',
        );
    }
}
