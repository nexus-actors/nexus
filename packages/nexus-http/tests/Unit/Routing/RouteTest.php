<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Routing;

use Monadial\Nexus\Http\RequestCtx;
use Monadial\Nexus\Http\Routing\Route;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(Route::class)]
final class RouteTest extends TestCase
{
    #[Test]
    public function it_runs_its_closure_with_the_given_context(): void
    {
        $captured = null;
        $route = new Route(static function (RequestCtx $ctx) use (&$captured): ResponseInterface {
            $captured = $ctx;

            return new Response(200);
        });

        $ctx = $this->createStub(RequestCtx::class);
        $response = ($route->run)($ctx);

        self::assertSame($ctx, $captured);
        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function it_can_return_null_to_signal_rejection(): void
    {
        $route = new Route(static fn(): ?ResponseInterface => null);
        $ctx = $this->createStub(RequestCtx::class);

        self::assertNull(($route->run)($ctx));
    }
}
