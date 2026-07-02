<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\WebSocket\Resolver;

use Monadial\Nexus\Http\Handler\Resolver\RequestBoundContext;
use Monadial\Nexus\Http\Handler\Resolver\ResolverServices;
use Monadial\Nexus\Http\Handler\Resolver\Scope;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketContext;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @psalm-api
 *
 * Resolution context active during WebSocketHandler construction. Carries the
 * per-connection WebSocketContext that #[FromContext] (and implicit type-hint
 * resolution) read.
 *
 * Lives in nexus-http-ws because nexus-http cannot reference WebSocketContext
 * without taking a hard dep on nexus-http-ws (the reverse of the actual dep
 * direction).
 */
final readonly class WsConnectionContext extends RequestBoundContext
{
    /**
     * @param array<string, string> $pathParams
     */
    public function __construct(
        ResolverServices $services,
        ServerRequestInterface $request,
        array $pathParams,
        public WebSocketContext $wsContext,
    ) {
        parent::__construct(Scope::WsConnection, $services, $request, $pathParams);
    }
}
