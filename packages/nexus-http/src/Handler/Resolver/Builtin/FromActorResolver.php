<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler\Resolver\Builtin;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Http\Exception\InvalidFromActorParameterException;
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
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use RuntimeException;

use function array_all;
use function array_any;
use function is_a;

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

        if ($ctx->services->actors === null) {
            throw new RuntimeException(
                "#[FromActor('{$actorName}')] used in {$ctx->owner} but no ResolvedActorTable wired",
            );
        }

        if (!$ctx->services->actors->hasAny($actorName)) {
            throw new UnknownActorException($actorName);
        }

        $isPerRequest = $ctx->services->actors->isPerRequest($actorName);

        if ($ctx->scope === Scope::HttpBoot && $isPerRequest) {
            throw new PerRequestActorInConstructorException($ctx->owner, $param->getName(), $actorName);
        }

        $reflectionType = $param->getType();

        if (!self::acceptsActorRef($reflectionType)) {
            throw new InvalidFromActorParameterException(
                $ctx->owner,
                $param->getName(),
                $actorName,
                (string) $reflectionType,
            );
        }

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

        if ($actors === null) {
            throw new RuntimeException(
                "FromActorResolver::resolve invoked without a ResolvedActorTable for \${$metadata->name}",
            );
        }

        if ($actors->isPerRequest($actorName)) {
            /** @var HttpRequestContext $ctx */
            return $ctx->perRequestScope->spawn($actorName);
        }

        return $actors->resolve($actorName);
    }

    /**
     * True when the declared parameter type can hold an ActorRef instance:
     * untyped, mixed, object, ActorRef (or an interface it implements), a
     * union with at least one accepting member, or an intersection whose
     * members are all satisfied by ActorRef.
     */
    private static function acceptsActorRef(?ReflectionType $type): bool
    {
        if ($type === null) {
            return true;
        }

        if ($type instanceof ReflectionNamedType) {
            $name = $type->getName();

            if ($name === 'mixed' || $name === 'object') {
                return true;
            }

            return self::classAcceptsActorRef($name);
        }

        if ($type instanceof ReflectionUnionType) {
            return array_any(
                $type->getTypes(),
                static fn(ReflectionType $member): bool => self::acceptsActorRef($member),
            );
        }

        if ($type instanceof ReflectionIntersectionType) {
            return array_all(
                $type->getTypes(),
                static fn(ReflectionNamedType $member): bool => self::classAcceptsActorRef($member->getName()),
            );
        }

        return false;
    }

    /**
     * True when the given class or interface name can hold an ActorRef —
     * i.e. ActorRef itself or an interface it implements.
     */
    private static function classAcceptsActorRef(string $name): bool
    {
        if (!class_exists($name) && !interface_exists($name)) {
            return false;
        }

        return is_a(ActorRef::class, $name, true);
    }
}
