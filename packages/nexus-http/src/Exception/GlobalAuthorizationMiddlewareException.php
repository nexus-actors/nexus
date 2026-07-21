<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Exception;

use Monadial\Nexus\Core\Exception\NexusException;

/**
 * @psalm-api
 *
 * Thrown at compile time when an AuthorizationEnforcer middleware is
 * registered in the GLOBAL HTTP stack. Global middleware runs before routing,
 * so the enforcer would never see the resolved handler class and every request
 * would fail (or, worse, pass unchecked). The enforcer must be registered
 * per-route, after routing.
 */
final class GlobalAuthorizationMiddlewareException extends NexusException
{
    public function __construct(string $middleware)
    {
        parent::__construct(
            "{$middleware} enforces route authorization and must be registered per-route, not globally: "
            . 'global middleware runs before routing, where no handler class is resolved yet. '
            . "Move it to the route: ->middleware({$middleware}::class).",
        );
    }
}
