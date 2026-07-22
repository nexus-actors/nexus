<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Toolkit\Tests\Unit\Middleware;

use Monadial\Nexus\Http\Toolkit\Middleware\TraceContextMiddleware;
use Monadial\Nexus\Logger\Mdc;
use Nyholm\Psr7\Factory\Psr17Factory;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(TraceContextMiddleware::class)]
final class TraceContextMiddlewareTest extends TestCase
{
    #[Test]
    public function generates_fresh_trace_when_header_is_absent(): void
    {
        $factory = new Psr17Factory();
        $captured = new RequestCapturingHandler($factory->createResponse(200));

        (new TraceContextMiddleware())->process(
            $factory->createServerRequest('GET', 'http://localhost/'),
            $captured,
        );

        $request = $captured->captured;
        self::assertNotNull($request);
        /** @var string $traceId */
        $traceId = $request->getAttribute('trace.id');
        /** @var string $spanId */
        $spanId = $request->getAttribute('trace.spanId');
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $traceId);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $spanId);
        self::assertSame('', $request->getAttribute('trace.parentSpanId'));
    }

    #[Test]
    public function parses_inbound_traceparent_and_preserves_trace_id(): void
    {
        $factory = new Psr17Factory();
        $captured = new RequestCapturingHandler($factory->createResponse(200));

        $request = $factory->createServerRequest('GET', 'http://localhost/')
            ->withHeader('traceparent', '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01');

        (new TraceContextMiddleware())->process($request, $captured);

        $captured_req = $captured->captured;
        self::assertNotNull($captured_req);
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $captured_req->getAttribute('trace.id'));
        self::assertSame('00f067aa0ba902b7', $captured_req->getAttribute('trace.parentSpanId'));

        /** @var string $spanId */
        $spanId = $captured_req->getAttribute('trace.spanId');
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $spanId);
        self::assertNotSame('00f067aa0ba902b7', $spanId, 'this hop should have a fresh span id');
    }

    #[Test]
    public function malformed_traceparent_is_replaced_with_a_fresh_trace(): void
    {
        $factory = new Psr17Factory();
        $captured = new RequestCapturingHandler($factory->createResponse(200));

        $request = $factory->createServerRequest('GET', 'http://localhost/')
            ->withHeader('traceparent', 'not-a-valid-trace-parent');

        (new TraceContextMiddleware())->process($request, $captured);

        $captured_req = $captured->captured;
        self::assertNotNull($captured_req);
        /** @var string $traceId */
        $traceId = $captured_req->getAttribute('trace.id');
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $traceId);
        self::assertSame('', $captured_req->getAttribute('trace.parentSpanId'));
    }

    #[Test]
    public function response_carries_outbound_traceparent(): void
    {
        $factory = new Psr17Factory();
        $captured = new RequestCapturingHandler($factory->createResponse(200));

        $response = (new TraceContextMiddleware())->process(
            $factory->createServerRequest('GET', 'http://localhost/'),
            $captured,
        );

        self::assertMatchesRegularExpression(
            '/^00-[0-9a-f]{32}-[0-9a-f]{16}-[0-9a-f]{2}$/',
            $response->getHeaderLine('traceparent'),
        );
    }

    /**
     * The middleware writes trace context into the process-static MDC tier; clear it so
     * the keys do not leak into later tests that assert on exact MDC contents.
     */
    #[Override]
    protected function tearDown(): void
    {
        Mdc::clearStatic();
    }
}

final class RequestCapturingHandler implements RequestHandlerInterface
{
    public ?ServerRequestInterface $captured = null;

    public function __construct(private readonly ResponseInterface $response) {}

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->captured = $request;

        return $this->response;
    }
}
