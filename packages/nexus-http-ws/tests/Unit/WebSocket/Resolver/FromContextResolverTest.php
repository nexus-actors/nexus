<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket\Resolver;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Http\Actor\ResolvedActorTable;
use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\ResolverServices;
use Monadial\Nexus\Http\Handler\Resolver\Scope;
use Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket\Support\InMemoryWebSocketContext;
use Monadial\Nexus\Http\Ws\WebSocket\Attribute\FromContext;
use Monadial\Nexus\Http\Ws\WebSocket\Resolver\FromContextResolver;
use Monadial\Nexus\Http\Ws\WebSocket\Resolver\WsConnectionContext;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketContext;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;
use ReflectionParameter;
use RuntimeException;

#[CoversClass(FromContextResolver::class)]
final class FromContextResolverTest extends TestCase
{
    #[Test]
    public function returns_null_when_scope_is_not_ws_connection(): void
    {
        $resolver = new FromContextResolver();
        $param = $this->refOf(static function (#[FromContext] WebSocketContext $ctx): void {});

        self::assertNull($resolver->compile($param, $this->ctx(Scope::HttpBoot)));
        self::assertNull($resolver->compile($param, $this->ctx(Scope::HttpRequest)));
    }

    #[Test]
    public function returns_null_when_no_from_context_attribute(): void
    {
        $resolver = new FromContextResolver();
        $param = $this->refOf(static function (WebSocketContext $ctx): void {});

        self::assertNull($resolver->compile($param, $this->ctx(Scope::WsConnection)));
    }

    #[Test]
    public function throws_runtime_exception_on_wrong_parameter_type(): void
    {
        $resolver = new FromContextResolver();
        $param = $this->refOf(static function (#[FromContext] string $ctx): void {});

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('#[FromContext]');

        $resolver->compile($param, $this->ctx(Scope::WsConnection));
    }

    #[Test]
    public function compiles_metadata_and_resolves_to_ctx_ws_context(): void
    {
        $resolver = new FromContextResolver();
        $param = $this->refOf(static function (#[FromContext] WebSocketContext $ctx): void {});
        $services = $this->services();

        $metadata = $resolver->compile($param, new CompileContext(Scope::WsConnection, 'TestOwner', $services));

        self::assertNotNull($metadata);
        self::assertSame('ctx', $metadata->name);
        self::assertSame(WebSocketContext::class, $metadata->type);

        $wsContext = new InMemoryWebSocketContext(42);
        $invocationCtx = new WsConnectionContext(
            $services,
            new ServerRequest('GET', '/ws/echo'),
            [],
            $wsContext,
        );

        self::assertSame($wsContext, $resolver->resolve($metadata, $invocationCtx));
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
