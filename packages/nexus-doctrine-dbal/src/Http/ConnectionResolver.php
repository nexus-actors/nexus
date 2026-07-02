<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Http;

use Doctrine\DBAL\Connection;
use Monadial\Nexus\Doctrine\Dbal\Exception\MissingConnectionScopeException;
use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\InvocationContext;
use Monadial\Nexus\Http\Handler\Resolver\ParamMetadata;
use Monadial\Nexus\Http\Handler\Resolver\ParamResolver;
use Monadial\Nexus\Http\Handler\Resolver\RequestBoundContext;
use Override;
use ReflectionNamedType;
use ReflectionParameter;

/** @psalm-api */
final class ConnectionResolver implements ParamResolver
{
    #[Override]
    public function compile(ReflectionParameter $param, CompileContext $ctx): ?ParamMetadata
    {
        $type = $param->getType();

        if (!$type instanceof ReflectionNamedType) {
            return null;
        }

        if ($type->getName() !== Connection::class) {
            return null;
        }

        return new ParamMetadata(
            resolver: $this,
            name: $param->getName(),
            type: Connection::class,
        );
    }

    #[Override]
    public function resolve(ParamMetadata $metadata, InvocationContext $ctx): Connection
    {
        if (!$ctx instanceof RequestBoundContext) {
            throw new MissingConnectionScopeException();
        }

        $lease = $ctx->request->getAttribute(ConnectionLease::class);

        if (!$lease instanceof ConnectionLease) {
            throw new MissingConnectionScopeException();
        }

        return $lease->get();
    }
}
