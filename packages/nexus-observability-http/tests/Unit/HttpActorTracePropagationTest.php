<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Http\Tests\Unit;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Observability\Context\BaggagePropagator;
use Monadial\Nexus\Observability\Context\CompositePropagator;
use Monadial\Nexus\Observability\Context\TraceContextPropagator;
use Monadial\Nexus\Observability\Http\ServerSpanMiddleware;
use Monadial\Nexus\Observability\Otel\OtelObservability;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as MetricInMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversNothing]
final class HttpActorTracePropagationTest extends TestCase
{
    #[Test]
    #[WithoutErrorHandler]
    public function httpRequestToActorProducesOneConnectedTrace(): void
    {
        $exporter = new InMemoryExporter();
        $tracerProvider = new TracerProvider(new SimpleSpanProcessor($exporter));
        $observability = new OtelObservability(
            $tracerProvider,
            MeterProvider::builder()->addReader(new ExportingReader(new MetricInMemoryExporter()))->build(),
            new CompositePropagator([new TraceContextPropagator(), new BaggagePropagator()]),
        );

        $runtime = new FiberRuntime();
        $system = ActorSystem::create('http-test', $runtime, null, null, null, $observability);
        $worker = $system->spawn(
            Props::fromBehavior(Behavior::receive(static fn (ActorContext $ctx, object $msg): Behavior => Behavior::same())),
            'worker',
        );

        // Handler tells an actor while the Server span is active.
        $handler = new class($worker) implements RequestHandlerInterface {
            /** @param ActorRef<object> $worker */
            public function __construct(private readonly ActorRef $worker) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->worker->tell(new HttpWork());

                return new Response(200);
            }
        };

        $middleware = new ServerSpanMiddleware($observability);
        $runtime->scheduleOnce(Duration::zero(), static function () use ($middleware, $handler): void {
            $middleware->process(new ServerRequest('POST', 'https://api.test/orders'), $handler);
        });
        $runtime->scheduleOnce(Duration::millis(300), static fn () => $system->shutdown(Duration::seconds(1)));
        $system->run();
        $tracerProvider->forceFlush();

        $spans = $exporter->getSpans();
        self::assertGreaterThanOrEqual(2, count($spans));

        $server = null;
        $consumer = null;

        foreach ($spans as $span) {
            if ($span->getKind() === 2) {
                $server = $span;
            }

            if ($span->getName() === 'process HttpWork') {
                $consumer = $span;
            }
        }

        self::assertNotNull($server, 'server span missing');
        self::assertNotNull($consumer, 'actor consumer span missing');
        self::assertSame($server->getTraceId(), $consumer->getTraceId());
        self::assertSame($server->getSpanId(), $consumer->getParentSpanId());
    }
}

final readonly class HttpWork {}
