<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\Tests\Unit;

use Attribute;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Http\Exception\UnprotectedRouteException;
use Monadial\Nexus\Http\Security\AuthorizationEnforcer;
use Monadial\Nexus\Http\Security\AuthorizationRequirement;
use Monadial\Nexus\Http\Ws\WsApplication;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class _WsRequiresThing implements AuthorizationRequirement {}

#[_WsRequiresThing]
final class _ProtectedWsHandler {}

final class _WsEnforcer implements AuthorizationEnforcer, MiddlewareInterface
{
    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request);
    }
}

#[CoversClass(WsApplication::class)]
final class WsApplicationAuthGuardTest extends TestCase
{
    #[Test]
    public function annotated_ws_route_without_enforcer_fails_compilation(): void
    {
        $app = WsApplication::create($this->system());
        $app->ws('/ws/secure', _ProtectedWsHandler::class);

        $this->expectException(UnprotectedRouteException::class);
        $this->expectExceptionMessage(_ProtectedWsHandler::class);
        $app->compile();
    }

    #[Test]
    public function annotated_ws_route_with_global_ws_enforcer_compiles(): void
    {
        $app = WsApplication::create($this->system())
            ->wsMiddleware(new _WsEnforcer());
        $app->ws('/ws/secure', _ProtectedWsHandler::class);

        $app->compile();

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function annotated_ws_route_with_route_enforcer_compiles(): void
    {
        $app = WsApplication::create($this->system());
        $app->ws('/ws/secure', _ProtectedWsHandler::class, [new _WsEnforcer()]);

        $app->compile();

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function annotated_channel_route_without_enforcer_fails_compilation(): void
    {
        $app = WsApplication::create($this->system());
        $app->channel('/chat/{room}', _ProtectedWsHandler::class, 'room');

        $this->expectException(UnprotectedRouteException::class);
        $app->compile();
    }

    private function system(): ActorSystem
    {
        return ActorSystem::create('ws-auth-guard-test', new TestRuntime());
    }
}
