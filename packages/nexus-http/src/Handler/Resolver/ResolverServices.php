<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler\Resolver;

use Monadial\Nexus\Http\Actor\ResolvedActorTable;
use Monadial\Nexus\Serialization\MessageSerializer;
use Psr\Container\ContainerInterface;

/**
 * @psalm-api
 *
 * The bag of services every InvocationContext carries. Resolvers reach into
 * this for the container, the message serializer, and the actor table.
 * Symmetric across HTTP and WebSocket call sites — WS handlers can use
 * #[FromActor] / #[FromService] / a hypothetical #[FromFrame] just like HTTP
 * handlers.
 */
final readonly class ResolverServices
{
    public function __construct(
        public ?ResolvedActorTable $actors = null,
        public ?ContainerInterface $container = null,
        public ?MessageSerializer $serializer = null,
    ) {}
}
