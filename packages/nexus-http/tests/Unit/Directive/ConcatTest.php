<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Directive;

use Monadial\Nexus\Http\Routing\Route;
use Monadial\Nexus\Http\Tests\Unit\Directive\Helpers\CtxFactory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

use function Monadial\Nexus\Http\concat;

final class ConcatTest extends TestCase
{
    #[Test]
    public function returns_first_non_null_route(): void
    {
        $reject = new Route(static fn() => null);
        $accept = new Route(static fn(): ResponseInterface => new Response(200));

        $route = concat($reject, $accept);

        $response = ($route->run)(CtxFactory::with('GET', '/'));
        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function returns_null_if_all_reject(): void
    {
        $route = concat(
            new Route(static fn() => null),
            new Route(static fn() => null),
        );

        self::assertNull(($route->run)(CtxFactory::with('GET', '/')));
    }

    #[Test]
    public function returns_null_if_no_children(): void
    {
        self::assertNull((concat()->run)(CtxFactory::with('GET', '/')));
    }
}
