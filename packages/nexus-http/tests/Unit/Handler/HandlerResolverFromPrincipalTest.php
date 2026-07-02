<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Handler;

use LogicException;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Http\Actor\PerRequestActorScope;
use Monadial\Nexus\Http\Actor\ResolvedActorTable;
use Monadial\Nexus\Http\Auth\Attribute\FromPrincipal;
use Monadial\Nexus\Http\Auth\Exception\AuthMiddlewareNotRegisteredException;
use Monadial\Nexus\Http\Auth\Resolver\FromPrincipalResolver;
use Monadial\Nexus\Http\Handler\HandlerResolver;
use Monadial\Nexus\Http\Handler\Resolver\Builtin\ContainerFallbackResolver;
use Monadial\Nexus\Http\Handler\Resolver\Builtin\FromActorResolver;
use Monadial\Nexus\Http\Handler\Resolver\Builtin\FromBodyResolver;
use Monadial\Nexus\Http\Handler\Resolver\Builtin\FromServiceResolver;
use Monadial\Nexus\Http\Handler\Resolver\Builtin\PathParamResolver;
use Monadial\Nexus\Http\Handler\Resolver\Builtin\PerRequestScopeResolver;
use Monadial\Nexus\Http\Handler\Resolver\Builtin\ServerRequestResolver;
use Monadial\Nexus\Http\Handler\Resolver\ParamResolverRegistry;
use Monadial\Nexus\Http\Response\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use stdClass;

final class _PrincipalInvokeHandler
{
    public function __invoke(ServerRequestInterface $r, #[FromPrincipal] stdClass $principal): ResponseInterface
    {
        /** @var string $id */
        $id = $principal->id;

        return Response::ok()->withHeader('X-Principal-Id', $id);
    }
}

final readonly class _PrincipalCtorHandler
{
    public function __construct(#[FromPrincipal] private stdClass $principal) {}

    public function __invoke(ServerRequestInterface $r): ResponseInterface
    {
        unset($this->principal);

        return Response::ok();
    }
}

#[CoversClass(HandlerResolver::class)]
final class HandlerResolverFromPrincipalTest extends TestCase
{
    #[Test]
    public function from_principal_param_reads_the_principal_request_attribute(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $table = ResolvedActorTable::build([], $system, null);
        $resolver = new HandlerResolver($table, null, null, $this->registry());

        $resolved = $resolver->resolve(_PrincipalInvokeHandler::class);

        $principal = new stdClass();
        $principal->id = 'tomas';
        $request = (new ServerRequest('GET', '/me'))->withAttribute('principal', $principal);

        $scope = new PerRequestActorScope($system, [], 'r-1');
        $response = ($resolved->invoke)($request, $scope, []);

        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('tomas', $response->getHeaderLine('X-Principal-Id'));
    }

    #[Test]
    public function from_principal_in_constructor_throws_logic_exception(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $table = ResolvedActorTable::build([], $system, null);
        $resolver = new HandlerResolver($table, null, null, $this->registry());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('#[FromPrincipal]');

        $resolver->resolve(_PrincipalCtorHandler::class);
    }

    #[Test]
    public function from_principal_missing_request_attribute_throws_logic_exception(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $table = ResolvedActorTable::build([], $system, null);
        $resolver = new HandlerResolver($table, null, null, $this->registry());

        $resolved = $resolver->resolve(_PrincipalInvokeHandler::class);

        $scope = new PerRequestActorScope($system, [], 'r-1');

        $this->expectException(AuthMiddlewareNotRegisteredException::class);
        $this->expectExceptionMessage('AuthenticationMiddleware');

        ($resolved->invoke)(new ServerRequest('GET', '/me'), $scope, []);
    }

    private function registry(): ParamResolverRegistry
    {
        return (new ParamResolverRegistry())
            ->with(new FromActorResolver())
            ->with(new FromBodyResolver())
            ->with(new FromServiceResolver())
            ->with(new ServerRequestResolver())
            ->with(new PerRequestScopeResolver())
            ->with(new PathParamResolver())
            ->with(new ContainerFallbackResolver())
            ->with(new FromPrincipalResolver());
    }
}
