<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Http;

use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionPool;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/** @psalm-api */
final readonly class ConnectionScopeMiddleware implements MiddlewareInterface
{
    public function __construct(private ConnectionPool $pool) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $lease = new ConnectionLease($this->pool);
        $request = $request->withAttribute(ConnectionLease::class, $lease);

        try {
            return $handler->handle($request);
        } catch (Throwable $e) {
            $lease->poison();

            throw $e;
        } finally {
            $lease->release();
        }
    }
}
