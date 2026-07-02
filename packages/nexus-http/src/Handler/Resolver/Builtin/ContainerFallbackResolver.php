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
 * container->get($type). Otherwise fall back to the parameter's default
 * value (if any), then to null (if the type is nullable).
 *
 * Only active in constructor scopes (HttpBoot, WsConnection). HttpRequest
 * fall-through is a misuse — the existing HandlerResolver throws there too.
 */
final readonly class ContainerFallbackResolver implements ParamResolver
{
    private const string MODE_CONTAINER = 'container';
    private const string MODE_DEFAULT = 'default';
    private const string MODE_NULL = 'null';

    #[Override]
    public function compile(ReflectionParameter $param, CompileContext $ctx): ?ParamMetadata
    {
        if ($ctx->scope === Scope::HttpRequest) {
            return null;
        }

        $type = $param->getType();

        if (
            $ctx->services->container !== null
            && $type instanceof ReflectionNamedType
            && !$type->isBuiltin()
            && $ctx->services->container->has($type->getName())
        ) {
            return new ParamMetadata(
                resolver: $this,
                name: $param->getName(),
                type: $type->getName(),
                payload: ['mode' => self::MODE_CONTAINER],
            );
        }

        if ($param->isDefaultValueAvailable()) {
            /** @var mixed $default */
            $default = $param->getDefaultValue();

            return new ParamMetadata(
                resolver: $this,
                name: $param->getName(),
                type: $type instanceof ReflectionNamedType ? $type->getName() : null,
                payload: ['default' => $default, 'mode' => self::MODE_DEFAULT],
            );
        }

        if ($type instanceof ReflectionNamedType && $type->allowsNull()) {
            return new ParamMetadata(
                resolver: $this,
                name: $param->getName(),
                type: $type->getName(),
                payload: ['mode' => self::MODE_NULL],
            );
        }

        return null;
    }

    #[Override]
    public function resolve(ParamMetadata $metadata, InvocationContext $ctx): mixed
    {
        /** @var string $mode */
        $mode = $metadata->payload['mode'];

        if ($mode === self::MODE_DEFAULT) {
            return $metadata->payload['default'];
        }

        if ($mode === self::MODE_NULL) {
            return null;
        }

        if ($ctx->services->container === null) {
            throw new RuntimeException("ContainerFallbackResolver invoked without a container for \${$metadata->name}");
        }

        /** @var string $type */
        $type = $metadata->type;

        return $ctx->services->container->get($type);
    }
}
