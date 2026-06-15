<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler\Resolver\Builtin;

use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\InvocationContext;
use Monadial\Nexus\Http\Handler\Resolver\ParamMetadata;
use Monadial\Nexus\Http\Handler\Resolver\ParamResolver;
use Monadial\Nexus\Http\Handler\Resolver\Scope;
use Override;
use ReflectionNamedType;
use ReflectionParameter;
use RuntimeException;

/**
 * @psalm-api
 *
 * Last-resort constructor fallback: when no other resolver matches and the
 * parameter has a class type-hint bound in the container, resolve via
 * container->get($type).
 *
 * Only active in constructor scopes (HttpBoot, WsConnection). HttpRequest
 * fall-through is a misuse — the existing HandlerResolver throws there too.
 */
final readonly class ContainerFallbackResolver implements ParamResolver
{
    #[Override]
    public function compile(ReflectionParameter $param, CompileContext $ctx): ?ParamMetadata
    {
        if ($ctx->scope === Scope::HttpRequest) {
            return null;
        }

        if ($ctx->services->container === null) {
            return null;
        }

        $type = $param->getType();

        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        if (!$ctx->services->container->has($type->getName())) {
            return null;
        }

        return new ParamMetadata(
            resolver: $this,
            name: $param->getName(),
            type: $type->getName(),
        );
    }

    #[Override]
    public function resolve(ParamMetadata $metadata, InvocationContext $ctx): mixed
    {
        if ($ctx->services->container === null) {
            throw new RuntimeException("ContainerFallbackResolver invoked without a container for \${$metadata->name}");
        }

        /** @var string $type */
        $type = $metadata->type;

        return $ctx->services->container->get($type);
    }
}
