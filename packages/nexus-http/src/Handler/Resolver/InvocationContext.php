<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler\Resolver;

/**
 * @psalm-api
 *
 * Common base for every per-call resolution context. Carries the scope (enum)
 * and the services (container/serializer/actors) that any resolver might need.
 *
 * Sealed via PHP convention — only the three concrete subclasses in this
 * namespace (HttpBootContext, HttpRequestContext) and one in nexus-http-ws
 * (WsConnectionContext) extend this. The framework itself never instantiates
 * the abstract base.
 */
abstract readonly class InvocationContext
{
    public function __construct(public Scope $scope, public ResolverServices $services) {}
}
