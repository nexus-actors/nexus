<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Http\Tests\Unit;

use Monadial\Nexus\Observability\Context\BaggagePropagator;
use Monadial\Nexus\Observability\Context\CompositePropagator;
use Monadial\Nexus\Observability\Context\TraceContextPropagator;
use Monadial\Nexus\Observability\Http\ServerSpanMiddleware;
use Monadial\Nexus\Observability\Otel\OtelObservability;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as MetricInMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

#[CoversClass(ServerSpanMiddleware::class)]
final class ServerSpanMiddlewareTest extends TestCase
{
    private InMemoryExporter $exporter;
    private TracerProvider $tracerProvider;
    private OtelObservability $observability;

    protected function setUp(): void
    {
        $this->exporter = new InMemoryExporter();
        $this->tracerProvider = new TracerProvider(new SimpleSpanProcessor($this->exporter));
        $meterProvider = MeterProvider::builder()
            ->addReader(new ExportingReader(new MetricInMemoryExporter()))
            ->build();
        $this->observability = new OtelObservability(
            $this->tracerProvider,
            $meterProvider,
            new CompositePropagator([new TraceContextPropagator(), new BaggagePropagator()]),
        );
    }

    #[Test]
    public function opensServerSpanUnderInboundTraceparentWithHttpAttributes(): void
    {
        $middleware = new ServerSpanMiddleware($this->observability);
        $request = (new ServerRequest('GET', 'https://api.test/orders/42'))
            ->withHeader('traceparent', '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01');

        $response = $middleware->process($request, $this->handlerReturning(new Response(200)));

        self::assertSame(200, $response->getStatusCode());
        $this->tracerProvider->forceFlush();
        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame('0af7651916cd43dd8448eb211c80319c', $spans[0]->getTraceId());
        self::assertSame('b7ad6b7169203331', $spans[0]->getParentSpanId());
        self::assertSame(2, $spans[0]->getKind()); // SERVER
        self::assertSame('GET', $spans[0]->getAttributes()->get('http.request.method'));
        self::assertSame('/orders/42', $spans[0]->getAttributes()->get('url.path'));
        self::assertSame(200, $spans[0]->getAttributes()->get('http.response.status_code'));
    }

    #[Test]
    public function marksServerErrorStatusOnFivexx(): void
    {
        $middleware = new ServerSpanMiddleware($this->observability);
        $middleware->process(new ServerRequest('GET', 'https://api.test/'), $this->handlerReturning(new Response(503)));

        $this->tracerProvider->forceFlush();
        self::assertSame('Error', $this->exporter->getSpans()[0]->getStatus()->getCode());
    }

    #[Test]
    public function disabledObservabilityIsPassThrough(): void
    {
        $middleware = new ServerSpanMiddleware(new ThrowingWhenDisabledObservability());
        $response = $middleware->process(
            new ServerRequest('GET', 'https://api.test/'),
            $this->handlerReturning(new Response(204)),
        );

        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function recordsHandlerExceptionAndRethrows(): void
    {
        $middleware = new ServerSpanMiddleware($this->observability);
        $throwing = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new RuntimeException('boom');
            }
        };

        try {
            $middleware->process(new ServerRequest('POST', 'https://api.test/x'), $throwing);
            self::fail('exception should propagate');
        } catch (RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        $this->tracerProvider->forceFlush();
        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame('Error', $spans[0]->getStatus()->getCode());
    }

    private function handlerReturning(ResponseInterface $response): RequestHandlerInterface
    {
        return new class($response) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }
}
