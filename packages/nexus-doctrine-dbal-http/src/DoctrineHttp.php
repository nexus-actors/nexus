<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Http;

use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionPool;
use Monadial\Nexus\Http\Handler\Resolver\ParamResolverRegistry;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseFactoryInterface;

/** @psalm-api */
final class DoctrineHttp
{
    /**
     * Wires Doctrine DBAL HTTP integration into the application.
     *
     * Returns the updated (immutable) registry with ConnectionResolver appended.
     * Appends ConnectionScopeMiddleware and PoolExhaustedToServiceUnavailable
     * to the provided $middlewares list.
     *
     * @param list<object> $middlewares
     * @param-out list<object> $middlewares
     */
    public static function install(
        ParamResolverRegistry $registry,
        array &$middlewares,
        ConnectionPool $connPool,
        ?ResponseFactoryInterface $responseFactory = null,
    ): ParamResolverRegistry {
        $middlewares[] = new ConnectionScopeMiddleware($connPool);
        $middlewares[] = new PoolExhaustedToServiceUnavailable($responseFactory ?? new Psr17Factory());

        return $registry->with(new ConnectionResolver());
    }
}
