<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Middleware;

use Monadial\Nexus\Http\Middleware\MiddlewarePipeline;
use Monadial\Nexus\Http\Response\Response;
use Nyholm\Psr7\Response as Psr7Response;
use Nyholm\Psr7\ServerRequest;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class _AddHeaderMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly string $name, private readonly string $value) {}

    #[Override]
    public function process(ServerRequestInterface $r, RequestHandlerInterface $next): ResponseInterface
    {
        return $next->handle($r)->withHeader($this->name, $this->value);
    }
}

#[CoversClass(MiddlewarePipeline::class)]
final class MiddlewarePipelineTest extends TestCase
{
    #[Test]
    public function empty_chain_calls_tail_directly(): void
    {
        $pipeline = new MiddlewarePipeline(container: null);

        $response = $pipeline->process(
            [],
            new ServerRequest('GET', '/'),
            static fn(): ResponseInterface => new Psr7Response(204),
        );

        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function runs_chain_outside_in_response_unwinds_inside_out(): void
    {
        $pipeline = new MiddlewarePipeline(container: null);

        $response = $pipeline->process(
            [new _AddHeaderMiddleware('X-A', '1'), new _AddHeaderMiddleware('X-B', '2')],
            new ServerRequest('GET', '/'),
            static fn(): ResponseInterface => Response::ok(),
        );

        self::assertSame('1', $response->getHeaderLine('X-A'));
        self::assertSame('2', $response->getHeaderLine('X-B'));
    }
}
