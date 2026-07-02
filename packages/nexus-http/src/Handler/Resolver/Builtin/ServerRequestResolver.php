<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler\Resolver\Builtin;

use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\InvocationContext;
use Monadial\Nexus\Http\Handler\Resolver\ParamMetadata;
use Monadial\Nexus\Http\Handler\Resolver\ParamResolver;
use Monadial\Nexus\Http\Handler\Resolver\RequestBoundContext;
use Override;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * @psalm-api
 *
 * Resolves any parameter typed ServerRequestInterface to the current request,
 * but only in request-bound scopes. Skipped in HttpBoot (no request at boot).
 */
final readonly class ServerRequestResolver implements ParamResolver
{
    #[Override]
    public function compile(ReflectionParameter $param, CompileContext $ctx): ?ParamMetadata
    {
        if (!$ctx->isRequestBound()) {
            return null;
        }

        $type = $param->getType();

        if (!$type instanceof ReflectionNamedType || $type->getName() !== ServerRequestInterface::class) {
            return null;
        }

        return new ParamMetadata(resolver: $this, name: $param->getName(), type: ServerRequestInterface::class);
    }

    #[Override]
    public function resolve(ParamMetadata $metadata, InvocationContext $ctx): mixed
    {
        /** @var RequestBoundContext $ctx */
        return $ctx->request;
    }
}
