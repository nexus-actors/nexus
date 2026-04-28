<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Directive;

use Monadial\Nexus\Http\DefaultRequestCtx;
use Monadial\Nexus\Http\RequestCtx;
use Monadial\Nexus\Http\Routing\Route;
use Nyholm\Psr7\Response;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Wraps a Route + RequestCtx into a PSR-15 RequestHandlerInterface so PSR-15
 * middlewares can adapt the request before the route runs.
 */
final class RouteHandler implements RequestHandlerInterface
{
    /** @param list<MiddlewareInterface> $middlewares */
    public function __construct(
        private readonly Route $route,
        private readonly RequestCtx $ctx,
        private readonly array $middlewares = [],
        private readonly int $offset = 0,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (isset($this->middlewares[$this->offset])) {
            return $this->middlewares[$this->offset]->process(
                $request,
                new self($this->route, $this->ctx, $this->middlewares, $this->offset + 1),
            );
        }

        $ctx = $this->ctx instanceof DefaultRequestCtx
            ? new DefaultRequestCtx(
                logger: $this->ctx->logger,
                params: $this->ctx->params,
                registry: $this->ctx->registry,
                request: $request,
                system: $this->ctx->system,
            )
            : $this->ctx;

        $response = ($this->route->run)($ctx);

        return $response ?? new Response(404);
    }
}
