<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler\Resolver;

/**
 * @psalm-api
 *
 * Resolution context active when an HTTP handler is being constructed at boot
 * time (HandlerResolver::instantiate). No request is available yet — services
 * only. Resolvers that need request-bound data (FromBody, FromPrincipal,
 * ServerRequest, ...) return null at compile time when given this scope.
 */
final readonly class HttpBootContext extends InvocationContext
{
    public function __construct(ResolverServices $services)
    {
        parent::__construct(Scope::HttpBoot, $services);
    }
}
