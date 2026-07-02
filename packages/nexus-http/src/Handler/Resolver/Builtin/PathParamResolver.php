<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler\Resolver\Builtin;

use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\InvocationContext;
use Monadial\Nexus\Http\Handler\Resolver\ParamMetadata;
use Monadial\Nexus\Http\Handler\Resolver\ParamResolver;
use Monadial\Nexus\Http\Handler\Resolver\RequestBoundContext;
use Override;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * @psalm-api
 *
 * Recognises string-typed parameters in request-bound scopes as path
 * parameters. Looks up by parameter name in the route's matched path params.
 * Missing names resolve to empty string (matches existing HandlerResolver
 * behavior).
 */
final readonly class PathParamResolver implements ParamResolver
{
    #[Override]
    public function compile(ReflectionParameter $param, CompileContext $ctx): ?ParamMetadata
    {
        if (!$ctx->isRequestBound()) {
            return null;
        }

        $type = $param->getType();

        if (!$type instanceof ReflectionNamedType || $type->getName() !== 'string') {
            return null;
        }

        return new ParamMetadata(resolver: $this, name: $param->getName(), type: 'string');
    }

    #[Override]
    public function resolve(ParamMetadata $metadata, InvocationContext $ctx): mixed
    {
        if (!$ctx instanceof RequestBoundContext) {
            return '';
        }

        return $ctx->pathParams[$metadata->name] ?? '';
    }
}
