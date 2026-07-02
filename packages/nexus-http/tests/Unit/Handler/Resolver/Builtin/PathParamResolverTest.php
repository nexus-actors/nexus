<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Handler\Resolver\Builtin;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Http\Actor\PerRequestActorScope;
use Monadial\Nexus\Http\Actor\ResolvedActorTable;
use Monadial\Nexus\Http\Handler\Resolver\Builtin\PathParamResolver;
use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\HttpRequestContext;
use Monadial\Nexus\Http\Handler\Resolver\ResolverServices;
use Monadial\Nexus\Http\Handler\Resolver\Scope;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;
use ReflectionParameter;

#[CoversClass(PathParamResolver::class)]
final class PathParamResolverTest extends TestCase
{
    #[Test]
    public function compiles_metadata_for_string_param_in_request_scope(): void
    {
        $resolver = new PathParamResolver();
        $param = $this->refOf(static function (string $id): void {});
        $services = $this->services();

        $metadata = $resolver->compile($param, new CompileContext(Scope::HttpRequest, 'TestOwner', $services));

        self::assertNotNull($metadata);
        self::assertSame('id', $metadata->name);
        self::assertSame('string', $metadata->type);

        $invocationCtx = new HttpRequestContext(
            $services,
            new ServerRequest('GET', '/users/42'),
            ['id' => '42'],
            new PerRequestActorScope(ActorSystem::create('test', new TestRuntime()), [], 'req-1'),
        );

        self::assertSame('42', $resolver->resolve($metadata, $invocationCtx));
    }

    #[Test]
    public function returns_null_for_non_string_typed_parameter(): void
    {
        $resolver = new PathParamResolver();
        $param = $this->refOf(static function (int $id): void {});

        self::assertNull($resolver->compile($param, $this->bootCtx(Scope::HttpRequest)));
    }

    #[Test]
    public function returns_null_in_boot_scope(): void
    {
        $resolver = new PathParamResolver();
        $param = $this->refOf(static function (string $id): void {});

        self::assertNull($resolver->compile($param, $this->bootCtx(Scope::HttpBoot)));
    }

    #[Test]
    public function resolves_to_empty_string_when_path_param_missing(): void
    {
        $resolver = new PathParamResolver();
        $param = $this->refOf(static function (string $missing): void {});
        $services = $this->services();

        $metadata = $resolver->compile($param, new CompileContext(Scope::HttpRequest, 'TestOwner', $services));
        self::assertNotNull($metadata);

        $invocationCtx = new HttpRequestContext(
            $services,
            new ServerRequest('GET', '/'),
            [],
            new PerRequestActorScope(ActorSystem::create('test', new TestRuntime()), [], 'req-1'),
        );

        self::assertSame('', $resolver->resolve($metadata, $invocationCtx));
    }

    private function bootCtx(Scope $scope): CompileContext
    {
        return new CompileContext($scope, 'TestOwner', $this->services());
    }

    private function refOf(callable $fn): ReflectionParameter
    {
        return (new ReflectionFunction($fn))->getParameters()[0];
    }

    private function services(): ResolverServices
    {
        $system = ActorSystem::create('test', new TestRuntime());

        return new ResolverServices(
            ResolvedActorTable::build([], $system, null),
        );
    }
}
