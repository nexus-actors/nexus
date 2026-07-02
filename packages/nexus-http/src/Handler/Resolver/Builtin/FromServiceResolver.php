<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler\Resolver\Builtin;

use Monadial\Nexus\Http\Handler\Attribute\FromService;
use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\InvocationContext;
use Monadial\Nexus\Http\Handler\Resolver\ParamMetadata;
use Monadial\Nexus\Http\Handler\Resolver\ParamResolver;
use Override;
use ReflectionNamedType;
use ReflectionParameter;
use RuntimeException;

/**
 * @psalm-api
 *
 * Recognises #[FromService(Id::class)] and resolves via the PSR-11 container.
 * The service id is captured in the payload at compile time; at request time
 * we look it up in the container present on InvocationContext::$services.
 *
 * When the attribute id is null (#[FromService] alone), the parameter's
 * type-hint is used as the container id.
 *
 * Throws at resolve time if no container is wired.
 */
final readonly class FromServiceResolver implements ParamResolver
{
    #[Override]
    public function compile(ReflectionParameter $param, CompileContext $ctx): ?ParamMetadata
    {
        $attrs = $param->getAttributes(FromService::class);

        if ($attrs === []) {
            return null;
        }

        $reflectionType = $param->getType();
        $type = $reflectionType instanceof ReflectionNamedType
            ? $reflectionType->getName()
            : null;

        $attribute = $attrs[0]->newInstance();
        $serviceId = $attribute->id ?? $type;

        return new ParamMetadata(
            resolver: $this,
            name: $param->getName(),
            type: $type,
            payload: ['serviceId' => $serviceId],
        );
    }

    #[Override]
    public function resolve(ParamMetadata $metadata, InvocationContext $ctx): mixed
    {
        if ($ctx->services->container === null) {
            throw new RuntimeException(
                "Cannot resolve #[FromService] param \${$metadata->name}: no PSR-11 container wired",
            );
        }

        /** @var string $serviceId */
        $serviceId = $metadata->payload['serviceId'];

        return $ctx->services->container->get($serviceId);
    }
}
