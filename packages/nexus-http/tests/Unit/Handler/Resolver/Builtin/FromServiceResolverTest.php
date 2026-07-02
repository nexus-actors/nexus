<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Handler\Resolver\Builtin;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Http\Actor\PerRequestActorScope;
use Monadial\Nexus\Http\Actor\ResolvedActorTable;
use Monadial\Nexus\Http\Handler\Attribute\FromService;
use Monadial\Nexus\Http\Handler\Resolver\Builtin\FromServiceResolver;
use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
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
use stdClass;

#[CoversClass(FromServiceResolver::class)]
final class FromServiceResolverTest extends TestCase
{
    #[Test]
    public function returns_null_when_parameter_has_no_from_service_attribute(): void
    {
        $resolver = new FromServiceResolver();
        $param = $this->refOf(static function (string $x): void {});

        self::assertNull($resolver->compile($param, $this->ctx(null)));
    }

    #[Test]
    public function compiles_metadata_and_resolves_via_container(): void
    {
        $service = new stdClass();
        $service->id = 'logger.audit';
        $container = $this->container(['logger.audit' => $service]);

        $resolver = new FromServiceResolver();
        $param = $this->refOf(
            static function (#[FromService('logger.audit')] object $log): void {},
        );
        $services = $this->services($container);

        $metadata = $resolver->compile($param, new CompileContext(Scope::HttpBoot, 'TestOwner', $services));

        self::assertNotNull($metadata);
        self::assertSame('log', $metadata->name);
        self::assertSame('logger.audit', $metadata->payload['serviceId']);

        $invocationCtx = new HttpRequestContext(
            $services,
            new ServerRequest('GET', '/'),
            [],
            new PerRequestActorScope(ActorSystem::create('test', new TestRuntime()), [], 'req-1'),
        );

        self::assertSame($service, $resolver->resolve($metadata, $invocationCtx));
    }

    #[Test]
    public function resolve_throws_when_no_container_wired(): void
    {
        $resolver = new FromServiceResolver();
        $param = $this->refOf(
            static function (#[FromService('logger.audit')] object $log): void {},
        );
        $services = $this->services(null);

        $metadata = $resolver->compile($param, new CompileContext(Scope::HttpBoot, 'TestOwner', $services));
        self::assertNotNull($metadata);

        $invocationCtx = new HttpRequestContext(
            $services,
            new ServerRequest('GET', '/'),
            [],
            new PerRequestActorScope(ActorSystem::create('test', new TestRuntime()), [], 'req-1'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no PSR-11 container wired');

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

    private function ctx(?ContainerInterface $container): CompileContext
    {
        return new CompileContext(Scope::HttpBoot, 'TestOwner', $this->services($container));
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
