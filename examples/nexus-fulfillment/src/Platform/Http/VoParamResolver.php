<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Platform\Http;

use InvalidArgumentException;
use Monadial\Nexus\Http\Exception\GenericHttpException;
use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\InvocationContext;
use Monadial\Nexus\Http\Handler\Resolver\ParamMetadata;
use Monadial\Nexus\Http\Handler\Resolver\ParamResolver;
use Monadial\Nexus\Http\Handler\Resolver\RequestBoundContext;
use Override;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * @psalm-api
 *
 * Resolves SharedKernel value objects with a single string constructor
 * from route path parameters. Handles OrderId, Sku, TenantId — any class
 * in the SharedKernel namespace whose constructor takes exactly one string.
 */
final readonly class VoParamResolver implements ParamResolver
{
    private const string SHARED_KERNEL_NS = 'Monadial\\Nexus\\Example\\Fulfillment\\SharedKernel\\';

    #[Override]
    public function compile(ReflectionParameter $param, CompileContext $ctx): ?ParamMetadata
    {
        if (!$ctx->isRequestBound()) {
            return null;
        }

        $type = $param->getType();

        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $className = $type->getName();

        if (!str_starts_with($className, self::SHARED_KERNEL_NS)) {
            return null;
        }

        try {
            $ctor = (new ReflectionClass($className))->getConstructor();
        } catch (ReflectionException) {
            return null;
        }

        if ($ctor === null) {
            return null;
        }

        $params = $ctor->getParameters();

        if (count($params) !== 1) {
            return null;
        }

        $ctorType = $params[0]->getType();

        if (!$ctorType instanceof ReflectionNamedType || $ctorType->getName() !== 'string') {
            return null;
        }

        return new ParamMetadata(resolver: $this, name: $param->getName(), type: $className);
    }

    #[Override]
    public function resolve(ParamMetadata $metadata, InvocationContext $ctx): mixed
    {
        if (!$ctx instanceof RequestBoundContext) {
            return null;
        }

        $raw = $ctx->pathParams[$metadata->name] ?? null;

        if ($raw === null) {
            return null;
        }

        /** @var class-string $className */
        $className = $metadata->type;

        try {
            /** @psalm-suppress MixedMethodCall */
            return new $className($raw);
        } catch (InvalidArgumentException $e) {
            throw new GenericHttpException(400, $e->getMessage());
        }
    }
}
