<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\WebSocket;

use Monadial\Nexus\Http\Actor\ResolvedActorTable;
use Monadial\Nexus\Http\Handler\Resolver\Builtin\ContainerFallbackResolver;
use Monadial\Nexus\Http\Handler\Resolver\Builtin\FromActorResolver;
use Monadial\Nexus\Http\Handler\Resolver\Builtin\FromServiceResolver;
use Monadial\Nexus\Http\Handler\Resolver\Builtin\PathParamResolver;
use Monadial\Nexus\Http\Handler\Resolver\Builtin\ServerRequestResolver;
use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\ParamMetadata;
use Monadial\Nexus\Http\Handler\Resolver\ParamResolver;
use Monadial\Nexus\Http\Handler\Resolver\ParamResolverRegistry;
use Monadial\Nexus\Http\Handler\Resolver\ResolverServices;
use Monadial\Nexus\Http\Handler\Resolver\Scope;
use Monadial\Nexus\Http\Ws\WebSocket\Resolver\FromContextResolver;
use Monadial\Nexus\Http\Ws\WebSocket\Resolver\WsConnectionContext;
use Monadial\Nexus\Serialization\MessageSerializer;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;

use function array_map;
use function count;
use function is_string;
use function str_starts_with;

/**
 * @psalm-api
 *
 * Reflection-driven instantiation of WebSocketHandler subclasses. Dispatches
 * constructor parameter resolution through a shared ParamResolverRegistry,
 * symmetric with nexus-http's HandlerResolver.
 */
final readonly class HandlerInstantiator
{
    private LoggerInterface $logger;

    /**
     * @param list<ParamResolver> $userResolvers Application-registered param
     *        resolvers (e.g. FromPrincipalResolver) shared with the HTTP side
     *        via WsApplication::paramResolver(). Consulted before the
     *        container fallback.
     */
    public function __construct(
        private ContainerInterface $container,
        ?LoggerInterface $logger = null,
        private ?ParamResolverRegistry $registry = null,
        private ?ResolvedActorTable $actors = null,
        private ?MessageSerializer $serializer = null,
        private array $userResolvers = [],
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @param class-string<WebSocketHandler> $handlerClass
     */
    public function instantiate(string $handlerClass, WebSocketContext $ctx): WebSocketHandler
    {
        $rc = new ReflectionClass($handlerClass);
        $ctor = $rc->getConstructor();

        if ($ctor === null) {
            $this->logger->debug(
                'HandlerInstantiator: zero-arg handler',
                ['class' => $handlerClass, 'fd' => $ctx->id()],
            );

            /** @var WebSocketHandler */
            return $rc->newInstance();
        }

        $services = $this->services();
        $compileCtx = new CompileContext(Scope::WsConnection, $handlerClass, $services);
        $registry = $this->registry();

        $metadata = array_map(
            static fn($p): ParamMetadata => $registry->compile($p, $compileCtx),
            $ctor->getParameters(),
        );

        $invocationCtx = new WsConnectionContext(
            $services,
            $ctx->request(),
            $this->extractPathParams($ctx->request()),
            $ctx,
        );

        $args = array_map(
            static fn(ParamMetadata $m): mixed => $m->resolver->resolve($m, $invocationCtx),
            $metadata,
        );

        $this->logger->debug('HandlerInstantiator: handler instantiated', [
            'class' => $handlerClass,
            'fd' => $ctx->id(),
            'params' => count($args),
        ]);

        /** @var WebSocketHandler */
        return $rc->newInstanceArgs($args);
    }

    /**
     * @return array<string, string>
     */
    private function extractPathParams(ServerRequestInterface $request): array
    {
        $out = [];

        /** @var array<string, mixed> $all */
        $all = $request->getAttributes();

        /** @var mixed $value */
        foreach ($all as $key => $value) {
            if (is_string($value) && !str_starts_with($key, '_')) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private function registry(): ParamResolverRegistry
    {
        if ($this->registry !== null) {
            return $this->registry;
        }

        $registry = (new ParamResolverRegistry())
            ->with(new FromContextResolver())
            ->with(new FromServiceResolver())
            ->with(new ServerRequestResolver())
            ->with(new PathParamResolver());

        // User resolvers run before the container fallback so attribute-driven
        // resolution (e.g. #[FromPrincipal]) is not shadowed by type lookups.

        foreach ($this->userResolvers as $resolver) {
            $registry = $registry->with($resolver);
        }

        $registry = $registry->with(new ContainerFallbackResolver());

        if ($this->actors !== null) {
            $registry = $registry->withOverride(new FromActorResolver());
        }

        return $registry;
    }

    private function services(): ResolverServices
    {
        return new ResolverServices($this->actors, $this->container, $this->serializer);
    }
}
