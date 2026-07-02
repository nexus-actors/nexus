<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Handler\Resolver\Builtin;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Http\Actor\PerRequestActorScope;
use Monadial\Nexus\Http\Actor\ResolvedActorTable;
use Monadial\Nexus\Http\Handler\Resolver\Builtin\PerRequestScopeResolver;
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

#[CoversClass(PerRequestScopeResolver::class)]
final class PerRequestScopeResolverTest extends TestCase
{
    #[Test]
    public function returns_null_when_type_is_not_per_request_actor_scope(): void
    {
        $resolver = new PerRequestScopeResolver();
        $param = $this->refOf(static function (string $x): void {});

        self::assertNull($resolver->compile($param, $this->ctx(Scope::HttpRequest)));
    }

    #[Test]
    public function returns_null_in_boot_scope(): void
    {
        $resolver = new PerRequestScopeResolver();
        $param = $this->refOf(static function (PerRequestActorScope $scope): void {});

        self::assertNull($resolver->compile($param, $this->ctx(Scope::HttpBoot)));
    }

    #[Test]
    public function metadata_marks_needs_scope_true(): void
    {
        $resolver = new PerRequestScopeResolver();
        $param = $this->refOf(static function (PerRequestActorScope $scope): void {});

        $metadata = $resolver->compile($param, $this->ctx(Scope::HttpRequest));

        self::assertNotNull($metadata);
        self::assertTrue($metadata->needsScope);
        self::assertSame('scope', $metadata->name);
        self::assertSame(PerRequestActorScope::class, $metadata->type);
    }

    #[Test]
    public function resolves_to_the_per_request_scope(): void
    {
        $resolver = new PerRequestScopeResolver();
        $param = $this->refOf(static function (PerRequestActorScope $scope): void {});
        $services = $this->services();

        $metadata = $resolver->compile($param, new CompileContext(Scope::HttpRequest, 'TestOwner', $services));
        self::assertNotNull($metadata);

        $scope = new PerRequestActorScope(ActorSystem::create('test', new TestRuntime()), [], 'req-1');
        $invocationCtx = new HttpRequestContext(
            $services,
            new ServerRequest('GET', '/'),
            [],
            $scope,
        );

        self::assertSame($scope, $resolver->resolve($metadata, $invocationCtx));
    }

    private function ctx(Scope $scope): CompileContext
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

        return new ResolverServices(ResolvedActorTable::build([], $system, null));
    }
}
