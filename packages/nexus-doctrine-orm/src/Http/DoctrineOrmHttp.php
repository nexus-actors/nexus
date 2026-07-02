<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Http;

use Monadial\Nexus\Doctrine\Dbal\Http\PoolExhaustedToServiceUnavailable;
use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerPool;
use Monadial\Nexus\Http\Handler\Resolver\ParamResolverRegistry;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseFactoryInterface;

/** @psalm-api */
final class DoctrineOrmHttp
{
    /**
     * Wires Doctrine ORM HTTP integration into the application.
     *
     * Returns the updated (immutable) registry with EntityManagerResolver appended.
     * Appends EntityManagerScopeMiddleware and PoolExhaustedToServiceUnavailable
     * to the provided $middlewares list.
     *
     * @param list<object> $middlewares
     * @param-out list<object> $middlewares
     */
    public static function installOrm(
        ParamResolverRegistry $registry,
        array &$middlewares,
        EntityManagerPool $emPool,
        ?ResponseFactoryInterface $responseFactory = null,
    ): ParamResolverRegistry {
        $middlewares[] = new EntityManagerScopeMiddleware($emPool);
        $middlewares[] = new PoolExhaustedToServiceUnavailable($responseFactory ?? new Psr17Factory());

        return $registry->with(new EntityManagerResolver());
    }
}
