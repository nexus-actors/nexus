<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler\Resolver\Builtin;

use LogicException;
use Monadial\Nexus\Http\Handler\Attribute\FromBody;
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
 * Recognises #[FromBody] and deserializes the request body into the typed
 * DTO via the configured MessageSerializer. Only valid in request-bound
 * scopes (skips HttpBoot).
 *
 * Throws at compile time if the parameter lacks a class type hint or no
 * MessageSerializer is wired.
 */
final readonly class FromBodyResolver implements ParamResolver
{
    #[Override]
    public function compile(ReflectionParameter $param, CompileContext $ctx): ?ParamMetadata
    {
        $attrs = $param->getAttributes(FromBody::class);

        if ($attrs === []) {
            return null;
        }

        if (!$ctx->isRequestBound()) {
            return null;
        }

        $reflectionType = $param->getType();
        $type = $reflectionType instanceof ReflectionNamedType
            ? $reflectionType->getName()
            : null;

        if ($type === null) {
            throw new LogicException(
                "Cannot resolve {$ctx->owner} param \${$param->getName()} via #[FromBody] — no class type hint",
            );
        }

        if ($ctx->services->serializer === null) {
            throw new LogicException(
                "{$ctx->owner} param \${$param->getName()} uses #[FromBody] but no MessageSerializer is wired. "
                . 'Call HttpApp::withMessageSerializer(...) at boot.',
            );
        }

        return new ParamMetadata(resolver: $this, name: $param->getName(), type: $type);
    }

    #[Override]
    public function resolve(ParamMetadata $metadata, InvocationContext $ctx): mixed
    {
        if (!$ctx instanceof RequestBoundContext) {
            throw new LogicException(
                "FromBodyResolver invoked outside a request-bound context for \${$metadata->name}",
            );
        }

        /** @var string $type */
        $type = $metadata->type;
        $serializer = $ctx->services->serializer;

        if ($serializer === null) {
            throw new LogicException("FromBodyResolver invoked but no MessageSerializer wired for \${$metadata->name}");
        }

        return $serializer->deserialize((string) $ctx->request->getBody(), $type);
    }
}
