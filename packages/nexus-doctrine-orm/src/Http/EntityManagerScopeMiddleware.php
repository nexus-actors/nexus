<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Http;

use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerPool;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** @psalm-api */
final readonly class EntityManagerScopeMiddleware implements MiddlewareInterface
{
    public function __construct(private EntityManagerPool $pool) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $lease = new EntityManagerLease($this->pool);
        $request = $request->withAttribute(EntityManagerLease::class, $lease);

        try {
            return $handler->handle($request);
        } finally {
            $lease->release();
        }
    }
}
