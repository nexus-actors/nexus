<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler;

use Closure;
use LogicException;
use Monadial\Nexus\Http\Actor\PerRequestActorScope;
use Monadial\Nexus\Http\Actor\ResolvedActorTable;
use Monadial\Nexus\Http\Exception\PerRequestActorInConstructorException;
use Monadial\Nexus\Http\Exception\UnknownActorException;
use Monadial\Nexus\Http\Handler\Attribute\FromActor;
use Monadial\Nexus\Http\Handler\Attribute\FromService;
use Monadial\Nexus\Runtime\Async\Future;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionClass;
use ReflectionFunction;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;

/**
 * @psalm-api
 *
 * Walks a handler (class string, 'Class::method' string, or Closure) and
 * produces a ResolvedHandler. Reflection happens exactly once per handler.
 */
final class HandlerResolver
{
    public function __construct(
        private readonly ResolvedActorTable $actors,
        private readonly ?ContainerInterface $container,
    ) {}

    /**
     * @param class-string|Closure $handler
     */
    public function resolve(string|Closure $handler): ResolvedHandler
    {
        if ($handler instanceof Closure) {
            return $this->resolveClosure($handler);
        }

        if (str_contains($handler, '::')) {
            $parts = explode('::', $handler, 2);
            $class = $parts[0];
            $method = $parts[1] ?? '__invoke';

            /** @var class-string $class */
            return $this->resolveClassMethod($class, $method);
        }

        /** @var class-string $handler */
        return $this->resolveInvokableClass($handler);
    }

    /**
     * @param list<ParamMetadata> $params
     * @param array<string, string> $pathParams
     * @return list<mixed>
     */
    private function buildArgs(
        array $params,
        ServerRequestInterface $r,
        PerRequestActorScope $scope,
        array $pathParams,
    ): array {
        /** @var list<mixed> $args */
        $args = [];

        foreach ($params as $p) {
            /** @psalm-suppress MixedAssignment */
            $args[] = match ($p->kind) {
                ParamMetadata::KIND_SERVER_REQUEST => $r,
                ParamMetadata::KIND_REQUEST_SCOPE => $scope,
                ParamMetadata::KIND_PATH_PARAM => $pathParams[$p->name] ?? '',
                ParamMetadata::KIND_FROM_ACTOR => $this->resolveActorForCall($p, $scope),
                ParamMetadata::KIND_FROM_SERVICE => $this->resolveService($p->serviceId ?? $p->type ?? '', $p->name),
                ParamMetadata::KIND_CONTAINER => $this->resolveService($p->type ?? '', $p->name),
                default => throw new LogicException("Unsupported param kind: {$p->kind}"),
            };
        }

        return $args;
    }

    /**
     * @param array<int, ReflectionParameter> $params
     * @return list<ParamMetadata>
     */
    private function describeParams(array $params, bool $inConstructor, string $owner): array
    {
        $out = [];

        foreach ($params as $p) {
            $name = $p->getName();
            $reflectionType = $p->getType();
            $type = $reflectionType instanceof ReflectionNamedType
                ? $reflectionType->getName()
                : null;

            $fromActor = $p->getAttributes(FromActor::class);

            if ($fromActor !== []) {
                $actorName = $fromActor[0]->newInstance()->name;

                if (!$this->actors->hasAny($actorName)) {
                    throw new UnknownActorException($actorName);
                }

                if ($inConstructor && $this->actors->isPerRequest($actorName)) {
                    throw new PerRequestActorInConstructorException($owner, $name, $actorName);
                }

                $out[] = new ParamMetadata($name, $type, ParamMetadata::KIND_FROM_ACTOR, $actorName);

                continue;
            }

            $fromService = $p->getAttributes(FromService::class);

            if ($fromService !== []) {
                $serviceId = $fromService[0]->newInstance()->id;
                $out[] = new ParamMetadata($name, $type, ParamMetadata::KIND_FROM_SERVICE, serviceId: $serviceId);

                continue;
            }

            if ($type === ServerRequestInterface::class) {
                $out[] = new ParamMetadata($name, $type, ParamMetadata::KIND_SERVER_REQUEST);

                continue;
            }

            if ($type === PerRequestActorScope::class) {
                $out[] = new ParamMetadata($name, $type, ParamMetadata::KIND_REQUEST_SCOPE);

                continue;
            }

            if ($type === 'string' && !$inConstructor) {
                $out[] = new ParamMetadata($name, $type, ParamMetadata::KIND_PATH_PARAM);

                continue;
            }

            if ($inConstructor) {
                if ($type === null) {
                    throw new LogicException(
                        "Cannot resolve {$owner}::__construct(\${$name}) "
                        . '— no type hint and no #[FromActor]/#[FromService] attribute',
                    );
                }

                if ($this->container === null || !$this->container->has($type)) {
                    throw new LogicException(
                        "Cannot resolve {$owner}::__construct(\${$name}: {$type}) — no #[FromService] attribute "
                        . "and no PSR-11 container binding for '{$type}'",
                    );
                }

                $out[] = new ParamMetadata($name, $type, ParamMetadata::KIND_CONTAINER);

                continue;
            }

            throw new LogicException(
                "Cannot resolve {$owner} parameter \${$name}: add #[FromActor], "
                . 'type-hint ServerRequestInterface, PerRequestActorScope, or use string for path params',
            );
        }

        return $out;
    }

    /**
     * @param class-string $class
     * @param list<ParamMetadata> $ctorParams
     */
    private function instantiate(string $class, array $ctorParams): object
    {
        if ($this->container !== null && $this->container->has($class)) {
            /** @var object */
            return $this->container->get($class);
        }

        /** @var list<mixed> $args */
        $args = [];

        foreach ($ctorParams as $p) {
            /** @psalm-suppress MixedAssignment */
            $args[] = match ($p->kind) {
                ParamMetadata::KIND_FROM_ACTOR => $this->actors->resolve($p->actorName ?? ''),
                ParamMetadata::KIND_FROM_SERVICE => $this->resolveCtorService(
                    $class,
                    $p->name,
                    $p->serviceId ?? $p->type ?? '',
                ),
                ParamMetadata::KIND_CONTAINER => $this->resolveCtorService($class, $p->name, $p->type ?? ''),
                default => throw new LogicException("Unsupported constructor param kind: {$p->kind}"),
            };
        }

        /** @psalm-suppress MixedMethodCall */
        return new $class(...$args);
    }

    /** @param list<ParamMetadata> $params */
    private function paramsNeedScope(array $params): bool
    {
        foreach ($params as $p) {
            if ($p->kind === ParamMetadata::KIND_REQUEST_SCOPE) {
                return true;
            }

            if ($p->kind === ParamMetadata::KIND_FROM_ACTOR && $this->actors->isPerRequest($p->actorName ?? '')) {
                return true;
            }
        }

        return false;
    }

    private function resolveActorForCall(ParamMetadata $p, PerRequestActorScope $scope): object
    {
        $actorName = $p->actorName ?? '';

        if ($this->actors->isPerRequest($actorName)) {
            return $scope->spawn($actorName);
        }

        return $this->actors->resolve($actorName);
    }

    /**
     * @param class-string $class
     */
    private function resolveClassMethod(string $class, string $method): ResolvedHandler
    {
        $reflection = new ReflectionClass($class);
        $ctor = $reflection->getConstructor();
        $ctorParams = $ctor !== null
            ? $this->describeParams($ctor->getParameters(), inConstructor: true, owner: $class)
            : [];

        $methodRef = $reflection->getMethod($method);
        $invokeParams = $this->describeParams($methodRef->getParameters(), inConstructor: false, owner: $class);
        $returnIsFuture = $this->returnsFuture($methodRef->getReturnType());
        $needsScope = $this->paramsNeedScope($invokeParams);

        $instance = $this->instantiate($class, $ctorParams);

        $invoke =
            /**
             * @param array<string, string> $pathParams
             * @return Future<object>|ResponseInterface
             */
            function (
                ServerRequestInterface $r,
                PerRequestActorScope $scope,
                array $pathParams,
            ) use ($instance, $method, $invokeParams): mixed {
                $args = $this->buildArgs($invokeParams, $r, $scope, $pathParams);

                /** @psalm-suppress MixedMethodCall, MixedReturnStatement */
                return $instance->{$method}(...$args);
            };

        /** @psalm-suppress MixedArgumentTypeCoercion */
        return new ResolvedHandler($invoke, !$returnIsFuture, $needsScope);
    }

    private function resolveClosure(Closure $closure): ResolvedHandler
    {
        $reflection = new ReflectionFunction($closure);
        $invokeParams = $this->describeParams($reflection->getParameters(), inConstructor: false, owner: 'closure');
        $returnIsFuture = $this->returnsFuture($reflection->getReturnType());
        $needsScope = $this->paramsNeedScope($invokeParams);

        $invoke =
            /**
             * @param array<string, string> $pathParams
             * @return Future<object>|ResponseInterface
             */
            function (
                ServerRequestInterface $r,
                PerRequestActorScope $scope,
                array $pathParams,
            ) use ($closure, $invokeParams): mixed {
                $args = $this->buildArgs($invokeParams, $r, $scope, $pathParams);

                /** @psalm-suppress MixedReturnStatement */
                return $closure(...$args);
            };

        /** @psalm-suppress MixedArgumentTypeCoercion */
        return new ResolvedHandler($invoke, !$returnIsFuture, $needsScope);
    }

    /**
     * @param class-string $class
     */
    private function resolveCtorService(string $class, string $paramName, string $id): object
    {
        if ($this->container === null) {
            throw new LogicException("Cannot resolve {$class}::{$paramName} without a container");
        }

        /** @var object */
        return $this->container->get($id);
    }

    /**
     * @param class-string $class
     */
    private function resolveInvokableClass(string $class): ResolvedHandler
    {
        $reflection = new ReflectionClass($class);

        if ($reflection->hasMethod('__invoke')) {
            $method = '__invoke';
        } elseif ($reflection->implementsInterface(RequestHandlerInterface::class)) {
            $method = 'handle';
        } else {
            throw new LogicException("{$class} must declare __invoke() or implement RequestHandlerInterface");
        }

        return $this->resolveClassMethod($class, $method);
    }

    private function resolveService(string $id, string $paramName): mixed
    {
        if ($this->container === null) {
            throw new LogicException("Cannot resolve param \${$paramName} without a container");
        }

        return $this->container->get($id);
    }

    private function returnsFuture(?ReflectionType $type): bool
    {
        if (!$type instanceof ReflectionNamedType) {
            return false;
        }

        return $type->getName() === Future::class;
    }
}
