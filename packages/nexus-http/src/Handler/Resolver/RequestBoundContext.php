<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler\Resolver;

use Psr\Http\Message\ServerRequestInterface;

/**
 * @psalm-api
 *
 * Common base for invocation contexts that have a request. Path parameters
 * are matched at route time and stored here for PathParamResolver to read.
 *
 * Two concrete subclasses: HttpRequestContext (HTTP __invoke; adds
 * PerRequestActorScope) and WsConnectionContext (WS constructor; adds
 * WebSocketContext — lives in nexus-http-ws).
 */
abstract readonly class RequestBoundContext extends InvocationContext
{
    /**
     * @param array<string, string> $pathParams
     */
    public function __construct(
        Scope $scope,
        ResolverServices $services,
        public ServerRequestInterface $request,
        public array $pathParams,
    ) {
        parent::__construct($scope, $services);
    }
}
