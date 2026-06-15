<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler\Resolver\Builtin;

use Monadial\Nexus\Http\Exception\PerRequestActorInConstructorException;
use Monadial\Nexus\Http\Exception\UnknownActorException;
use Monadial\Nexus\Http\Handler\Attribute\FromActor;
use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\HttpRequestContext;
use Monadial\Nexus\Http\Handler\Resolver\InvocationContext;
use Monadial\Nexus\Http\Handler\Resolver\ParamMetadata;
use Monadial\Nexus\Http\Handler\Resolver\ParamResolver;
use Monadial\Nexus\Http\Handler\Resolver\Scope;
use Override;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * @psalm-api
 *
 * Recognises #[FromActor('name')] and resolves to either the singleton
 * ActorRef or a per-request actor spawned via PerRequestActorScope. Throws
 * if the actor name is unknown, or if a per-request actor appears in a
 * constructor scope (the constructor runs once at boot; per-request actors
 * live for one request).
 */
final readonly class FromActorResolver implements ParamResolver
{
    #[Override]
    public function compile(ReflectionParameter $param, CompileContext $ctx): ?ParamMetadata
    {
        $attrs = $param->getAttributes(FromActor::class);

        if ($attrs === []) {
            return null;
        }

        $actorName = $attrs[0]->newInstance()->name;

        if (!$ctx->services->actors->hasAny($actorName)) {
            throw new UnknownActorException($actorName);
        }

        $isPerRequest = $ctx->services->actors->isPerRequest($actorName);

        if ($ctx->scope === Scope::HttpBoot && $isPerRequest) {
            throw new PerRequestActorInConstructorException($ctx->owner, $param->getName(), $actorName);
        }

        $reflectionType = $param->getType();
        $type = $reflectionType instanceof ReflectionNamedType
            ? $reflectionType->getName()
            : null;

        return new ParamMetadata(
            resolver: $this,
            name: $param->getName(),
            type: $type,
            payload: ['actorName' => $actorName],
            needsScope: $isPerRequest,
        );
    }

    #[Override]
    public function resolve(ParamMetadata $metadata, InvocationContext $ctx): mixed
    {
        /** @var string $actorName */
        $actorName = $metadata->payload['actorName'];
        $actors = $ctx->services->actors;

        if ($actors->isPerRequest($actorName)) {
            /** @var HttpRequestContext $ctx */
            return $ctx->perRequestScope->spawn($actorName);
        }

        return $actors->resolve($actorName);
    }
}
