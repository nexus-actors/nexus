<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Handler\Resolver\Builtin;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Http\Actor\PerRequestActorScope;
use Monadial\Nexus\Http\Actor\ResolvedActorTable;
use Monadial\Nexus\Http\Handler\Resolver\Builtin\ServerRequestResolver;
use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\HttpRequestContext;
use Monadial\Nexus\Http\Handler\Resolver\ResolverServices;
use Monadial\Nexus\Http\Handler\Resolver\Scope;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionFunction;
use ReflectionParameter;

#[CoversClass(ServerRequestResolver::class)]
final class ServerRequestResolverTest extends TestCase
{
    #[Test]
    public function returns_null_when_type_is_not_server_request_interface(): void
    {
        $resolver = new ServerRequestResolver();
        $param = $this->refOf(static function (string $x): void {});

        self::assertNull($resolver->compile($param, $this->ctx(Scope::HttpRequest)));
    }

    #[Test]
    public function returns_null_in_boot_scope(): void
    {
        $resolver = new ServerRequestResolver();
        $param = $this->refOf(static function (ServerRequestInterface $request): void {});

        self::assertNull($resolver->compile($param, $this->ctx(Scope::HttpBoot)));
    }

    #[Test]
    public function resolves_to_the_current_request(): void
    {
        $resolver = new ServerRequestResolver();
        $param = $this->refOf(static function (ServerRequestInterface $request): void {});
        $services = $this->services();

        $metadata = $resolver->compile($param, new CompileContext(Scope::HttpRequest, 'TestOwner', $services));

        self::assertNotNull($metadata);
        self::assertSame('request', $metadata->name);
        self::assertSame(ServerRequestInterface::class, $metadata->type);

        $request = new ServerRequest('GET', '/hello');
        $invocationCtx = new HttpRequestContext(
            $services,
            $request,
            [],
            new PerRequestActorScope(ActorSystem::create('test', new TestRuntime()), [], 'req-1'),
        );

        self::assertSame($request, $resolver->resolve($metadata, $invocationCtx));
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
