<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Tests\Unit\Resolver;

use LogicException;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Http\Actor\PerRequestActorScope;
use Monadial\Nexus\Http\Actor\ResolvedActorTable;
use Monadial\Nexus\Http\Auth\Attribute\FromPrincipal;
use Monadial\Nexus\Http\Auth\Exception\AuthMiddlewareNotRegisteredException;
use Monadial\Nexus\Http\Auth\Exception\Unauthenticated;
use Monadial\Nexus\Http\Auth\Middleware\AuthenticationMiddleware;
use Monadial\Nexus\Http\Auth\Principal\SimplePrincipal;
use Monadial\Nexus\Http\Auth\Resolver\FromPrincipalResolver;
use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\HttpRequestContext;
use Monadial\Nexus\Http\Handler\Resolver\ResolverServices;
use Monadial\Nexus\Http\Handler\Resolver\Scope;
use Monadial\Nexus\Http\Ws\WebSocket\Resolver\WsConnectionContext;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketContext;
use Nyholm\Psr7\ServerRequest;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionFunction;
use ReflectionParameter;
use stdClass;

#[CoversClass(FromPrincipalResolver::class)]
final class FromPrincipalResolverTest extends TestCase
{
    #[Test]
    public function returns_null_when_no_from_principal_attribute(): void
    {
        $resolver = new FromPrincipalResolver();
        $param = $this->refOf(static function (stdClass $principal): void {});

        self::assertNull($resolver->compile($param, $this->compileCtx(Scope::HttpRequest)));
    }

    #[Test]
    public function throws_logic_exception_when_used_in_http_boot_scope(): void
    {
        $resolver = new FromPrincipalResolver();
        $param = $this->refOf(static function (#[FromPrincipal] stdClass $principal): void {});

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('#[FromPrincipal]');

        $resolver->compile($param, $this->compileCtx(Scope::HttpBoot));
    }

    #[Test]
    public function compiles_metadata_in_http_request_scope(): void
    {
        $resolver = new FromPrincipalResolver();
        $param = $this->refOf(static function (#[FromPrincipal] stdClass $principal): void {});

        $metadata = $resolver->compile($param, $this->compileCtx(Scope::HttpRequest));

        self::assertNotNull($metadata);
        self::assertSame('principal', $metadata->name);
        self::assertSame(stdClass::class, $metadata->type);
    }

    #[Test]
    public function compiles_metadata_in_ws_connection_scope(): void
    {
        $resolver = new FromPrincipalResolver();
        $param = $this->refOf(static function (#[FromPrincipal] stdClass $principal): void {});

        $metadata = $resolver->compile($param, $this->compileCtx(Scope::WsConnection));

        self::assertNotNull($metadata);
        self::assertSame('principal', $metadata->name);
        self::assertSame(stdClass::class, $metadata->type);
    }

    #[Test]
    public function resolves_to_request_principal_attribute_in_http_request_scope(): void
    {
        $resolver = new FromPrincipalResolver();
        $param = $this->refOf(static function (#[FromPrincipal] SimplePrincipal $principal): void {});
        $services = $this->services();

        $metadata = $resolver->compile(
            $param,
            new CompileContext(Scope::HttpRequest, 'TestOwner', $services),
        );

        self::assertNotNull($metadata);

        $principal = new SimplePrincipal('alice');
        $request = (new ServerRequest('GET', '/me'))->withAttribute('principal', $principal);
        $system = ActorSystem::create('test', new TestRuntime());
        $scope = new PerRequestActorScope($system, [], 'r-1');

        $invocationCtx = new HttpRequestContext($services, $request, [], $scope);

        self::assertSame($principal, $resolver->resolve($metadata, $invocationCtx));
    }

    #[Test]
    public function resolves_to_request_principal_attribute_in_ws_connection_scope(): void
    {
        $resolver = new FromPrincipalResolver();
        $param = $this->refOf(static function (#[FromPrincipal] SimplePrincipal $principal): void {});
        $services = $this->services();

        $metadata = $resolver->compile(
            $param,
            new CompileContext(Scope::WsConnection, 'TestOwner', $services),
        );

        self::assertNotNull($metadata);

        $principal = new SimplePrincipal('bob');
        $request = (new ServerRequest('GET', '/ws'))->withAttribute('principal', $principal);
        $wsContext = new StubWebSocketContext(7, $request);
        $invocationCtx = new WsConnectionContext($services, $request, [], $wsContext);

        self::assertSame($principal, $resolver->resolve($metadata, $invocationCtx));
    }

    #[Test]
    public function throws_middleware_not_registered_when_no_auth_attribute_stamped(): void
    {
        // No `nexus.auth.checked` attribute on the request → AuthenticationMiddleware
        // never ran. This is a config bug; the exception bubbles to a 500 with a
        // diagnostic hint, not a 401.
        $resolver = new FromPrincipalResolver();
        $param = $this->refOf(static function (#[FromPrincipal] stdClass $principal): void {});
        $services = $this->services();

        $metadata = $resolver->compile(
            $param,
            new CompileContext(Scope::HttpRequest, 'TestOwner', $services),
        );

        self::assertNotNull($metadata);

        $request = new ServerRequest('GET', '/me');
        $system = ActorSystem::create('test', new TestRuntime());
        $scope = new PerRequestActorScope($system, [], 'r-1');
        $invocationCtx = new HttpRequestContext($services, $request, [], $scope);

        $this->expectException(AuthMiddlewareNotRegisteredException::class);
        $this->expectExceptionMessage('AuthenticationMiddleware');

        $resolver->resolve($metadata, $invocationCtx);
    }

    #[Test]
    public function throws_unauthenticated_when_middleware_ran_but_no_principal(): void
    {
        // `nexus.auth.checked` set → middleware ran. Principal absent → caller
        // didn't present credentials. That's a user-facing 401, not a 500.
        $resolver = new FromPrincipalResolver();
        $param = $this->refOf(static function (#[FromPrincipal] stdClass $principal): void {});
        $services = $this->services();

        $metadata = $resolver->compile(
            $param,
            new CompileContext(Scope::HttpRequest, 'TestOwner', $services),
        );

        self::assertNotNull($metadata);

        $request = (new ServerRequest('GET', '/me'))
            ->withAttribute(AuthenticationMiddleware::CHECKED_ATTRIBUTE, true);
        $system = ActorSystem::create('test', new TestRuntime());
        $scope = new PerRequestActorScope($system, [], 'r-1');
        $invocationCtx = new HttpRequestContext($services, $request, [], $scope);

        $this->expectException(Unauthenticated::class);

        $resolver->resolve($metadata, $invocationCtx);
    }

    private function compileCtx(Scope $scope): CompileContext
    {
        return new CompileContext($scope, 'TestOwner', $this->services());
    }

    private function refOf(callable $fn): ReflectionParameter
    {
        return (new ReflectionFunction($fn(...)))->getParameters()[0];
    }

    private function services(): ResolverServices
    {
        $system = ActorSystem::create('test', new TestRuntime());

        return new ResolverServices(ResolvedActorTable::build([], $system, null));
    }
}

final readonly class StubWebSocketContext implements WebSocketContext
{
    public function __construct(private int $id, private ServerRequestInterface $request) {}

    #[Override]
    public function id(): int
    {
        return $this->id;
    }

    #[Override]
    public function request(): ServerRequestInterface
    {
        return $this->request;
    }

    #[Override]
    public function send(string $text): void
    {
        // no-op
    }

    #[Override]
    public function sendBinary(string $data): void
    {
        // no-op
    }

    #[Override]
    public function sendPing(): void
    {
        // no-op
    }

    #[Override]
    public function close(int $code = 1000, string $reason = ''): void
    {
        // no-op
    }

    #[Override]
    public function isAlive(): bool
    {
        return true;
    }

    #[Override]
    public function withRequest(ServerRequestInterface $request): WebSocketContext
    {
        return new self($this->id, $request);
    }
}
