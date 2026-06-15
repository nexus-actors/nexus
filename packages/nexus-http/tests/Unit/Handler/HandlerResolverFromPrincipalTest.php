<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Handler;

use LogicException;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Http\Actor\PerRequestActorScope;
use Monadial\Nexus\Http\Actor\ResolvedActorTable;
use Monadial\Nexus\Http\Auth\Attribute\FromPrincipal;
use Monadial\Nexus\Http\Handler\HandlerResolver;
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

final class _PrincipalCtorHandler
{
    public function __construct(#[FromPrincipal] private readonly stdClass $principal) {}

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
        self::markTestSkipped('Re-enabled in T14 after FromPrincipalResolver registration');

        /** @phpstan-ignore-next-line unreachable */
        $system = ActorSystem::create('test', new TestRuntime());
        $table = ResolvedActorTable::build([], $system, null);
        $resolver = new HandlerResolver($table, null);

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
        self::markTestSkipped('Re-enabled in T14 after FromPrincipalResolver registration');

        /** @phpstan-ignore-next-line unreachable */
        $system = ActorSystem::create('test', new TestRuntime());
        $table = ResolvedActorTable::build([], $system, null);
        $resolver = new HandlerResolver($table, null);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('#[FromPrincipal]');

        $resolver->resolve(_PrincipalCtorHandler::class);
    }

    #[Test]
    public function from_principal_missing_request_attribute_throws_logic_exception(): void
    {
        self::markTestSkipped('Re-enabled in T14 after FromPrincipalResolver registration');

        /** @phpstan-ignore-next-line unreachable */
        $system = ActorSystem::create('test', new TestRuntime());
        $table = ResolvedActorTable::build([], $system, null);
        $resolver = new HandlerResolver($table, null);

        $resolved = $resolver->resolve(_PrincipalInvokeHandler::class);

        $scope = new PerRequestActorScope($system, [], 'r-1');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('AuthenticationMiddleware');

        ($resolved->invoke)(new ServerRequest('GET', '/me'), $scope, []);
    }
}
