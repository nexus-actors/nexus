<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Http;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Dsl\HttpApp;
use Monadial\Nexus\Http\Exception\HttpException;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

#[CoversNothing]
final class ExceptionMappingTest extends TestCase
{
    #[Test]
    public function unknown_route_returns_404(): void
    {
        $system = ActorSystem::create('test-http', new FiberRuntime());
        $app = HttpApp::create($system);

        $response = $app->compile()->handle(new ServerRequest('GET', '/missing'));
        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function http_exception_thrown_in_handler_maps_to_status(): void
    {
        $system = ActorSystem::create('test-http', new FiberRuntime());
        $app = HttpApp::create($system);
        $app->get(
            '/forbidden',
            static fn(ServerRequestInterface $r): ResponseInterface => throw HttpException::forbidden(),
        );

        $response = $app->compile()->handle(new ServerRequest('GET', '/forbidden'));
        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function generic_throwable_maps_to_500_in_production_mode(): void
    {
        $system = ActorSystem::create('test-http', new FiberRuntime());
        $app = HttpApp::create($system);
        $app->get('/boom', static fn(): ResponseInterface => throw new RuntimeException('boom'));

        $response = $app->compile()->handle(new ServerRequest('GET', '/boom'));
        self::assertSame(500, $response->getStatusCode());
    }
}
