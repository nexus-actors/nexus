<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler\Resolver\Builtin;

use Monadial\Nexus\Http\Actor\PerRequestActorScope;
use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\HttpRequestContext;
use Monadial\Nexus\Http\Handler\Resolver\InvocationContext;
use Monadial\Nexus\Http\Handler\Resolver\ParamMetadata;
use Monadial\Nexus\Http\Handler\Resolver\ParamResolver;
use Monadial\Nexus\Http\Handler\Resolver\Scope;
use Override;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * @psalm-api
 *
 * Resolves any parameter typed PerRequestActorScope. Only valid in
 * Scope::HttpRequest. PerRequestActorScope does not exist in WS connections.
 *
 * Sets needsScope=true so HandlerMetadata::needsRequestScope correctly
 * triggers per-request scope allocation upstream.
 */
final readonly class PerRequestScopeResolver implements ParamResolver
{
    #[Override]
    public function compile(ReflectionParameter $param, CompileContext $ctx): ?ParamMetadata
    {
        if ($ctx->scope !== Scope::HttpRequest) {
            return null;
        }

        $type = $param->getType();

        if (!$type instanceof ReflectionNamedType || $type->getName() !== PerRequestActorScope::class) {
            return null;
        }

        return new ParamMetadata(
            resolver: $this,
            name: $param->getName(),
            type: PerRequestActorScope::class,
            needsScope: true,
        );
    }

    #[Override]
    public function resolve(ParamMetadata $metadata, InvocationContext $ctx): mixed
    {
        /** @var HttpRequestContext $ctx */
        return $ctx->perRequestScope;
    }
}
