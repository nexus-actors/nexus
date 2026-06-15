<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler;

use Closure;
use LogicException;
use Monadial\Nexus\Http\Actor\PerRequestActorScope;
use Monadial\Nexus\Http\Actor\ResolvedActorTable;
use Monadial\Nexus\Http\Handler\Resolver\Builtin\ContainerFallbackResolver;
use Monadial\Nexus\Http\Handler\Resolver\Builtin\FromActorResolver;
use Monadial\Nexus\Http\Handler\Resolver\Builtin\FromBodyResolver;
use Monadial\Nexus\Http\Handler\Resolver\Builtin\FromServiceResolver;
use Monadial\Nexus\Http\Handler\Resolver\Builtin\PathParamResolver;
use Monadial\Nexus\Http\Handler\Resolver\Builtin\PerRequestScopeResolver;
use Monadial\Nexus\Http\Handler\Resolver\Builtin\ServerRequestResolver;
use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\HttpBootContext;
use Monadial\Nexus\Http\Handler\Resolver\HttpRequestContext;
use Monadial\Nexus\Http\Handler\Resolver\ParamMetadata;
use Monadial\Nexus\Http\Handler\Resolver\ParamResolverRegistry;
use Monadial\Nexus\Http\Handler\Resolver\ResolverServices;
use Monadial\Nexus\Http\Handler\Resolver\Scope;
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Serialization\MessageSerializer;
use Nyholm\Psr7\Response;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionClass;
use ReflectionFunction;
use ReflectionParameter;

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
        private readonly ?MessageSerializer $serializer = null,
        private readonly ?ParamResolverRegistry $registry = null,
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
        $ctx = new HttpRequestContext($this->services(), $r, $pathParams, $scope);

        return array_map(
            static fn(ParamMetadata $p): mixed => $p->resolver->resolve($p, $ctx),
            $params,
        );
    }

    /**
     * @param array<int, ReflectionParameter> $params
     * @return list<ParamMetadata>
     */
    private function describeParams(array $params, bool $inConstructor, string $owner): array
    {
        $ctx = new CompileContext(
            $inConstructor
                ? Scope::HttpBoot
                : Scope::HttpRequest,
            $owner,
            $this->services(),
        );

        $registry = $this->registry();

        return array_values(array_map(
            static fn(ReflectionParameter $p): ParamMetadata => $registry->compile($p, $ctx),
            $params,
        ));
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

        $bootCtx = new HttpBootContext($this->services());
        $args = array_map(
            static fn(ParamMetadata $p): mixed => $p->resolver->resolve($p, $bootCtx),
            $ctorParams,
        );

        /** @psalm-suppress MixedMethodCall */
        return new $class(...$args);
    }

    /** @param list<ParamMetadata> $params */
    private function paramsNeedScope(array $params): bool
    {
        foreach ($params as $p) {
            if ($p->needsScope) {
                return true;
            }
        }

        return false;
    }

    /**
     * Post-process a handler return value: unwrap Future, pass ResponseInterface
     * through, otherwise serialize the typed object into a JSON response.
     */
    private function postProcess(mixed $result): ResponseInterface
    {
        if ($result instanceof Future) {
            /** @psalm-suppress MixedAssignment */
            $result = $result->await();
        }

        if ($result instanceof ResponseInterface) {
            return $result;
        }

        if (is_object($result)) {
            if ($this->serializer === null) {
                throw new LogicException('Handler returned a typed object but no MessageSerializer is wired');
            }

            return new Response(
                200,
                ['Content-Type' => 'application/json'],
                $this->serializer->serialize($result),
            );
        }

        throw new LogicException('Handler must return ResponseInterface, Future, or a typed object');
    }

    private function registry(): ParamResolverRegistry
    {
        if ($this->registry !== null) {
            return $this->registry;
        }

        return (new ParamResolverRegistry())
            ->with(new FromActorResolver())
            ->with(new FromBodyResolver())
            ->with(new FromServiceResolver())
            ->with(new ServerRequestResolver())
            ->with(new PerRequestScopeResolver())
            ->with(new PathParamResolver())
            ->with(new ContainerFallbackResolver());
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
        $needsScope = $this->paramsNeedScope($invokeParams);

        $instance = $this->instantiate($class, $ctorParams);

        $invoke =
            /**
             * @param array<string, string> $pathParams
             */
            function (
                ServerRequestInterface $r,
                PerRequestActorScope $scope,
                array $pathParams,
            ) use ($instance, $method, $invokeParams): ResponseInterface {
                $args = $this->buildArgs($invokeParams, $r, $scope, $pathParams);

                /** @psalm-suppress MixedMethodCall, MixedAssignment */
                $result = $instance->{$method}(...$args);

                return $this->postProcess($result);
            };

        /** @psalm-suppress MixedArgumentTypeCoercion */
        return new ResolvedHandler($invoke, true, $needsScope);
    }

    private function resolveClosure(Closure $closure): ResolvedHandler
    {
        $reflection = new ReflectionFunction($closure);
        $invokeParams = $this->describeParams($reflection->getParameters(), inConstructor: false, owner: 'closure');
        $needsScope = $this->paramsNeedScope($invokeParams);

        $invoke =
            /**
             * @param array<string, string> $pathParams
             */
            function (
                ServerRequestInterface $r,
                PerRequestActorScope $scope,
                array $pathParams,
            ) use ($closure, $invokeParams): ResponseInterface {
                $args = $this->buildArgs($invokeParams, $r, $scope, $pathParams);

                /** @psalm-suppress MixedAssignment */
                $result = $closure(...$args);

                return $this->postProcess($result);
            };

        /** @psalm-suppress MixedArgumentTypeCoercion */
        return new ResolvedHandler($invoke, true, $needsScope);
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

    private function services(): ResolverServices
    {
        return new ResolverServices($this->actors, $this->container, $this->serializer);
    }
}
