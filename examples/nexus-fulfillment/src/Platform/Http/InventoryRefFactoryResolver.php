<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Platform\Http;

use Monadial\Nexus\Example\Fulfillment\Inventory\Application\InventoryRefFactory;
use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\InvocationContext;
use Monadial\Nexus\Http\Handler\Resolver\ParamMetadata;
use Monadial\Nexus\Http\Handler\Resolver\ParamResolver;
use Override;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * @psalm-api
 *
 * Resolves InventoryRefFactory for handler __invoke parameters by type name.
 * Allows RestockHandler to be registered as a class-name route (required for
 * AuthorizationMiddleware reflection) while still receiving the
 * per-worker-boot singleton factory.
 */
final readonly class InventoryRefFactoryResolver implements ParamResolver
{
    public function __construct(private InventoryRefFactory $factory) {}

    #[Override]
    public function compile(ReflectionParameter $param, CompileContext $ctx): ?ParamMetadata
    {
        $type = $param->getType();

        if (!$type instanceof ReflectionNamedType || $type->getName() !== InventoryRefFactory::class) {
            return null;
        }

        return new ParamMetadata(resolver: $this, name: $param->getName(), type: InventoryRefFactory::class);
    }

    #[Override]
    public function resolve(ParamMetadata $metadata, InvocationContext $ctx): mixed
    {
        return $this->factory;
    }
}
