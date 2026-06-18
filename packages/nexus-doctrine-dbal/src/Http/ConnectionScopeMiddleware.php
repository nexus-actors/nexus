<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Http;

use Doctrine\DBAL\Exception as DbalException;
use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionPool;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * @psalm-api
 *
 * Scopes a `ConnectionLease` to one HTTP request. The lease is acquired lazily
 * via `$lease->get()` and always released at the end of the request.
 *
 * Poisoning policy: only `Doctrine\DBAL\Exception` (and subclasses) marks the
 * connection as poisoned, which evicts it from the pool. Domain or HTTP
 * exceptions (e.g. validation, 404) release the connection back to the pool
 * intact — they don't corrupt the connection's transaction or session state,
 * so evicting them would needlessly shrink the pool.
 */
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
        } catch (DbalException $e) {
            $lease->poison();

            throw $e;
        } finally {
            $lease->release();
        }
    }
}
