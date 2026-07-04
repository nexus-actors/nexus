<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Platform\Http;

use Monadial\Nexus\Example\Fulfillment\Orders\Application\OrderRefFactory;
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
 * Resolves OrderRefFactory for handler __invoke parameters by type name.
 * Allows PlaceOrderHandler and CancelOrderHandler to be registered as
 * class-name routes (required for AuthorizationMiddleware reflection)
 * while still receiving the per-worker-boot singleton factory.
 */
final readonly class OrderRefFactoryResolver implements ParamResolver
{
    public function __construct(private OrderRefFactory $factory) {}

    #[Override]
    public function compile(ReflectionParameter $param, CompileContext $ctx): ?ParamMetadata
    {
        $type = $param->getType();

        if (!$type instanceof ReflectionNamedType || $type->getName() !== OrderRefFactory::class) {
            return null;
        }

        return new ParamMetadata(resolver: $this, name: $param->getName(), type: OrderRefFactory::class);
    }

    #[Override]
    public function resolve(ParamMetadata $metadata, InvocationContext $ctx): mixed
    {
        return $this->factory;
    }
}
