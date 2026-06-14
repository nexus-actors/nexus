<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Toolkit\Tests\Unit\Middleware;

use Monadial\Nexus\Http\Toolkit\Middleware\BodySizeLimitMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(BodySizeLimitMiddleware::class)]
final class BodySizeLimitMiddlewareTest extends TestCase
{
    #[Test]
    public function rejects_post_with_oversized_content_length(): void
    {
        $mw = new BodySizeLimitMiddleware(maxBytes: 1024);

        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('POST', 'http://localhost/upload')
            ->withHeader('Content-Length', '2048');

        $response = $mw->process($request, new PassThroughHandler($factory->createResponse(200)));

        self::assertSame(413, $response->getStatusCode());
        self::assertStringContainsString('payload too large', (string) $response->getBody());
    }

    #[Test]
    public function rejects_when_body_stream_size_exceeds_limit(): void
    {
        $mw = new BodySizeLimitMiddleware(maxBytes: 10);

        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('POST', 'http://localhost/upload')
            ->withBody($factory->createStream('this body is definitely longer than 10 bytes'));

        $response = $mw->process($request, new PassThroughHandler($factory->createResponse(200)));

        self::assertSame(413, $response->getStatusCode());
    }

    #[Test]
    public function passes_through_when_body_fits(): void
    {
        $mw = new BodySizeLimitMiddleware(maxBytes: 1024);

        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('POST', 'http://localhost/upload')
            ->withHeader('Content-Length', '128')
            ->withBody($factory->createStream('small body'));

        $response = $mw->process($request, new PassThroughHandler($factory->createResponse(200)));

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function get_requests_bypass_the_check(): void
    {
        $mw = new BodySizeLimitMiddleware(maxBytes: 1);

        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('GET', 'http://localhost/orders/42')
            ->withHeader('Content-Length', '1024');

        $response = $mw->process($request, new PassThroughHandler($factory->createResponse(200)));

        self::assertSame(200, $response->getStatusCode());
    }
}

final readonly class PassThroughHandler implements RequestHandlerInterface
{
    public function __construct(private ResponseInterface $response) {}

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->response;
    }
}
