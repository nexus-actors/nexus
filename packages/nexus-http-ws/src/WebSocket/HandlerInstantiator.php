<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\WebSocket;

use Monadial\Nexus\Http\Ws\WebSocket\Attribute\FromContext;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use RuntimeException;

use function count;

/**
 * @psalm-api
 *
 * Reflection-driven instantiation of WebSocketHandler subclasses.
 * Walks constructor params: #[FromContext] resolves to the current context
 * (validated as WebSocketContext); everything else goes through PSR-11.
 *
 * #[FromActor] is left to the user's container layer to resolve via a
 * standard PSR-11 binding — this instantiator does not special-case it.
 */
final class HandlerInstantiator
{
    public function __construct(private readonly ContainerInterface $container) {}

    /**
     * @param class-string<WebSocketHandler> $handlerClass
     */
    public function instantiate(string $handlerClass, WebSocketContext $ctx): WebSocketHandler
    {
        $rc = new ReflectionClass($handlerClass);
        $ctor = $rc->getConstructor();

        if ($ctor === null) {
            /** @var WebSocketHandler */
            return $rc->newInstance();
        }

        $args = [];

        foreach ($ctor->getParameters() as $param) {
            /** @psalm-suppress MixedAssignment */
            $args[] = $this->resolveParam($param, $ctx, $handlerClass);
        }

        /** @var WebSocketHandler */
        return $rc->newInstanceArgs($args);
    }

    private function resolveParam(ReflectionParameter $param, WebSocketContext $ctx, string $handlerClass): mixed
    {
        $type = $param->getType();

        if (count($param->getAttributes(FromContext::class)) > 0) {
            if (!$type instanceof ReflectionNamedType || $type->getName() !== WebSocketContext::class) {
                throw new RuntimeException(
                    "#[FromContext] on {$handlerClass}::__construct(\${$param->getName()}) requires "
                    . 'parameter type ' . WebSocketContext::class . '.',
                );
            }

            return $ctx;
        }

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $id = $type->getName();

            if ($this->container->has($id)) {
                return $this->container->get($id);
            }
        }

        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }

        if ($type instanceof ReflectionNamedType && $type->allowsNull()) {
            return null;
        }

        throw new RuntimeException("Cannot resolve parameter \${$param->getName()} of {$handlerClass}::__construct.");
    }
}
