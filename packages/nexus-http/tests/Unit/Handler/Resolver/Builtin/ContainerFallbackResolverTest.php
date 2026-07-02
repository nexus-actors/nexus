<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Handler\Resolver\Builtin;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Http\Actor\PerRequestActorScope;
use Monadial\Nexus\Http\Actor\ResolvedActorTable;
use Monadial\Nexus\Http\Handler\Resolver\Builtin\ContainerFallbackResolver;
use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\HttpBootContext;
use Monadial\Nexus\Http\Handler\Resolver\HttpRequestContext;
use Monadial\Nexus\Http\Handler\Resolver\ResolverServices;
use Monadial\Nexus\Http\Handler\Resolver\Scope;
use Nyholm\Psr7\ServerRequest;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionFunction;
use ReflectionParameter;
use RuntimeException;

#[CoversClass(ContainerFallbackResolver::class)]
final class ContainerFallbackResolverTest extends TestCase
{
    #[Test]
    public function returns_null_in_http_request_scope(): void
    {
        $resolver = new ContainerFallbackResolver();
        $param = $this->refOf(static function (ContainerFallbackFakeService $svc): void {});
        $services = $this->services(
            $this->container([ContainerFallbackFakeService::class => new ContainerFallbackFakeService()]),
        );

        $ctx = new CompileContext(Scope::HttpRequest, 'TestOwner', $services);

        self::assertNull($resolver->compile($param, $ctx));
    }

    #[Test]
    public function returns_null_when_no_container_wired(): void
    {
        $resolver = new ContainerFallbackResolver();
        $param = $this->refOf(static function (ContainerFallbackFakeService $svc): void {});
        $services = $this->services(null);

        $ctx = new CompileContext(Scope::HttpBoot, 'TestOwner', $services);

        self::assertNull($resolver->compile($param, $ctx));
    }

    #[Test]
    public function returns_null_for_builtin_type(): void
    {
        $resolver = new ContainerFallbackResolver();
        $param = $this->refOf(static function (string $name): void {});
        $services = $this->services($this->container([]));

        $ctx = new CompileContext(Scope::HttpBoot, 'TestOwner', $services);

        self::assertNull($resolver->compile($param, $ctx));
    }

    #[Test]
    public function returns_null_when_container_lacks_binding(): void
    {
        $resolver = new ContainerFallbackResolver();
        $param = $this->refOf(static function (ContainerFallbackFakeService $svc): void {});
        $services = $this->services($this->container([]));

        $ctx = new CompileContext(Scope::HttpBoot, 'TestOwner', $services);

        self::assertNull($resolver->compile($param, $ctx));
    }

    #[Test]
    public function resolves_via_container_get_when_binding_present(): void
    {
        $service = new ContainerFallbackFakeService();
        $services = $this->services($this->container([ContainerFallbackFakeService::class => $service]));

        $resolver = new ContainerFallbackResolver();
        $param = $this->refOf(static function (ContainerFallbackFakeService $svc): void {});

        $metadata = $resolver->compile($param, new CompileContext(Scope::HttpBoot, 'TestOwner', $services));

        self::assertNotNull($metadata);
        self::assertSame('svc', $metadata->name);
        self::assertSame(ContainerFallbackFakeService::class, $metadata->type);

        $invocationCtx = new HttpBootContext($services);
        self::assertSame($service, $resolver->resolve($metadata, $invocationCtx));
    }

    #[Test]
    public function resolve_throws_when_container_disappeared(): void
    {
        $resolver = new ContainerFallbackResolver();
        $param = $this->refOf(static function (ContainerFallbackFakeService $svc): void {});
        $boundServices = $this->services(
            $this->container([ContainerFallbackFakeService::class => new ContainerFallbackFakeService()]),
        );

        $metadata = $resolver->compile($param, new CompileContext(Scope::HttpBoot, 'TestOwner', $boundServices));
        self::assertNotNull($metadata);

        $unboundServices = $this->services(null);
        $invocationCtx = new HttpRequestContext(
            $unboundServices,
            new ServerRequest('GET', '/'),
            [],
            new PerRequestActorScope(ActorSystem::create('test', new TestRuntime()), [], 'req-1'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invoked without a container');

        $resolver->resolve($metadata, $invocationCtx);
    }

    /**
     * @param array<string, object> $entries
     */
    private function container(array $entries): ContainerInterface
    {
        return new class ($entries) implements ContainerInterface {
            /**
             * @param array<string, object> $entries
             */
            public function __construct(private array $entries) {}

            #[Override]
            public function get(string $id): mixed
            {
                return $this->entries[$id] ?? throw new RuntimeException("Unknown: {$id}");
            }

            #[Override]
            public function has(string $id): bool
            {
                return isset($this->entries[$id]);
            }
        };
    }

    private function refOf(callable $fn): ReflectionParameter
    {
        return (new ReflectionFunction($fn))->getParameters()[0];
    }

    private function services(?ContainerInterface $container): ResolverServices
    {
        $system = ActorSystem::create('test', new TestRuntime());

        return new ResolverServices(
            ResolvedActorTable::build([], $system, null),
            $container,
        );
    }
}

final class ContainerFallbackFakeService
{
    public string $id = 'fake';
}
