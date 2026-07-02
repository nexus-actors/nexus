<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\WebSocket\Resolver;

use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\InvocationContext;
use Monadial\Nexus\Http\Handler\Resolver\ParamMetadata;
use Monadial\Nexus\Http\Handler\Resolver\ParamResolver;
use Monadial\Nexus\Http\Handler\Resolver\Scope;
use Monadial\Nexus\Http\Ws\WebSocket\Attribute\FromContext;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketContext;
use Override;
use ReflectionNamedType;
use ReflectionParameter;
use RuntimeException;

use function count;

/**
 * @psalm-api
 *
 * Resolves #[FromContext] WebSocketContext parameters on WebSocketHandler
 * constructors. Only valid in Scope::WsConnection.
 *
 * Replaces the hard-coded check in the old HandlerInstantiator::resolveParam().
 */
final readonly class FromContextResolver implements ParamResolver
{
    #[Override]
    public function compile(ReflectionParameter $param, CompileContext $ctx): ?ParamMetadata
    {
        if ($ctx->scope !== Scope::WsConnection) {
            return null;
        }

        if (count($param->getAttributes(FromContext::class)) === 0) {
            return null;
        }

        $type = $param->getType();

        if (!$type instanceof ReflectionNamedType || $type->getName() !== WebSocketContext::class) {
            throw new RuntimeException(
                "#[FromContext] on {$ctx->owner}::__construct(\${$param->getName()}) requires "
                . 'parameter type ' . WebSocketContext::class . '.',
            );
        }

        return new ParamMetadata(
            resolver: $this,
            name: $param->getName(),
            type: WebSocketContext::class,
        );
    }

    #[Override]
    public function resolve(ParamMetadata $metadata, InvocationContext $ctx): mixed
    {
        /** @var WsConnectionContext $ctx */
        return $ctx->wsContext;
    }
}
