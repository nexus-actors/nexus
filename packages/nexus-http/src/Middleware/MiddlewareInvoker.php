<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Middleware;

use Closure;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * @psalm-api
 *
 * Internal PSR-15 RequestHandlerInterface that walks a list of middlewares
 * and ends in a tail closure. Each next() call advances the index.
 */
final class MiddlewareInvoker implements RequestHandlerInterface
{
    /**
     * @param list<MiddlewareInterface> $middlewares
     * @param Closure(ServerRequestInterface): ResponseInterface $tail
     */
    public function __construct(
        private readonly array $middlewares,
        private readonly Closure $tail,
        private readonly int $index = 0,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!isset($this->middlewares[$this->index])) {
            return ($this->tail)($request);
        }

        $next = new self($this->middlewares, $this->tail, $this->index + 1);

        return $this->middlewares[$this->index]->process($request, $next);
    }
}
