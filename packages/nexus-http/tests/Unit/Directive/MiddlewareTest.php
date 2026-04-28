<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Directive;

use Monadial\Nexus\Http\Tests\Unit\Directive\Helpers\CtxFactory;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function Monadial\Nexus\Http\complete;
use function Monadial\Nexus\Http\useMiddleware;
use function Monadial\Nexus\Http\useMiddlewares;

final class MiddlewareTest extends TestCase
{
    #[Test]
    public function single_middleware_wraps_response(): void
    {
        $route = useMiddleware(
            new HeaderStampMiddleware('X-Trace', 'on'),
            static fn() => complete(['ok' => true]),
        );

        $response = ($route->run)(CtxFactory::with('GET', '/'));
        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame('on', $response->getHeaderLine('X-Trace'));
    }

    #[Test]
    public function multiple_middlewares_apply_in_order(): void
    {
        $route = useMiddlewares([
            new HeaderStampMiddleware('X-A', '1'),
            new HeaderStampMiddleware('X-B', '2'),
        ], static fn() => complete(['ok' => true]));

        $response = ($route->run)(CtxFactory::with('GET', '/'));
        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame('1', $response->getHeaderLine('X-A'));
        self::assertSame('2', $response->getHeaderLine('X-B'));
    }
}

final class HeaderStampMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly string $name, private readonly string $value) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        return $response->withHeader($this->name, $this->value);
    }
}
