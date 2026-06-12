<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\Tests\Unit;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Http\Dsl\HttpApp;
use Monadial\Nexus\Http\Ws\CompiledHttpApplication;
use Nyholm\Psr7\Response as Psr7Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompiledHttpApplication::class)]
final class CompiledHttpApplicationTest extends TestCase
{
    #[Test]
    public function delegates_handle_to_wrapped_compiled_http_app(): void
    {
        $system = ActorSystem::create('t', new TestRuntime());
        $http = HttpApp::create($system);
        $http->get('/ping', static fn() => new Psr7Response(200, [], 'pong'));

        $c = new CompiledHttpApplication($http->compile());

        $resp = $c->handle(new ServerRequest('GET', '/ping'));

        self::assertSame(200, $resp->getStatusCode());
        self::assertSame('pong', (string) $resp->getBody());
    }

    #[Test]
    public function has_web_socket_routes_is_always_false(): void
    {
        $system = ActorSystem::create('t', new TestRuntime());
        $http = HttpApp::create($system);
        $c = new CompiledHttpApplication($http->compile());

        self::assertFalse($c->hasWebSocketRoutes());
    }
}
