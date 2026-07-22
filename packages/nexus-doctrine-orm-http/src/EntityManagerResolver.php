<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Http;

use Doctrine\ORM\EntityManagerInterface;
use Monadial\Nexus\Doctrine\Orm\Exception\MissingEntityManagerScopeException;
use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\InvocationContext;
use Monadial\Nexus\Http\Handler\Resolver\ParamMetadata;
use Monadial\Nexus\Http\Handler\Resolver\ParamResolver;
use Monadial\Nexus\Http\Handler\Resolver\RequestBoundContext;
use Override;
use ReflectionNamedType;
use ReflectionParameter;

/** @psalm-api */
final class EntityManagerResolver implements ParamResolver
{
    #[Override]
    public function compile(ReflectionParameter $param, CompileContext $ctx): ?ParamMetadata
    {
        $type = $param->getType();

        if (!$type instanceof ReflectionNamedType) {
            return null;
        }

        if ($type->getName() !== EntityManagerInterface::class) {
            return null;
        }

        return new ParamMetadata(
            resolver: $this,
            name: $param->getName(),
            type: EntityManagerInterface::class,
        );
    }

    #[Override]
    public function resolve(ParamMetadata $metadata, InvocationContext $ctx): EntityManagerInterface
    {
        if (!$ctx instanceof RequestBoundContext) {
            throw new MissingEntityManagerScopeException();
        }

        $lease = $ctx->request->getAttribute(EntityManagerLease::class);

        if (!$lease instanceof EntityManagerLease) {
            throw new MissingEntityManagerScopeException();
        }

        return $lease->get();
    }
}
