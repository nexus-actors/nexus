<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Fiber\Observability;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Observability\Context\BaggagePropagator;
use Monadial\Nexus\Observability\Context\CompositePropagator;
use Monadial\Nexus\Observability\Context\TraceContextPropagator;
use Monadial\Nexus\Observability\Otel\OtelObservability;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
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

#[CoversNothing]
final class ActorTracePropagationTest extends TestCase
{
    /**
     * Verify that trace context injected into Envelope::metadata by the sender actor
     * (LocalActorRef::tell) is correctly extracted by the receiver actor, producing a
     * child span in the same trace.
     *
     * #[WithoutErrorHandler] is required because OpenTelemetry's FiberBoundContextStorage
     * emits E_USER_WARNING the first time any OTEL context operation occurs in a freshly
     * spawned PHP Fiber (automatic forking of parent context is not supported).
     * This does not affect propagation: the W3C traceparent is carried in Envelope::metadata,
     * not via fiber-local context inheritance, so all six assertions below still prove the
     * correct parent→child relationship.
     */
    #[Test]
    #[WithoutErrorHandler]
    public function tracePropagatesFromSenderActorToReceiverActor(): void
    {
        $exporter = new InMemoryExporter();
        $tracerProvider = new TracerProvider(new SimpleSpanProcessor($exporter));
        $meterProvider = MeterProvider::builder()
            ->addReader(new ExportingReader(new MetricInMemoryExporter()))
            ->build();
        $observability = new OtelObservability(
            $tracerProvider,
            $meterProvider,
            new CompositePropagator([new TraceContextPropagator(), new BaggagePropagator()]),
        );

        $runtime = new FiberRuntime();
        $system = ActorSystem::create('test', $runtime, null, null, null, $observability);

        // B echoes nothing; its Consumer span should parent under A's span.
        $b = $system->spawn(
            Props::fromBehavior(
                Behavior::receive(static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same()),
            ),
            'b',
        );

        // A, on receiving Go, tells B — the send happens inside A's active span.
        $a = $system->spawn(
            Props::fromBehavior(Behavior::receive(static function (ActorContext $ctx, object $msg) use ($b): Behavior {
                $b->tell(new TraceMsg());

                return Behavior::same();
            })),
            'a',
        );

        $a->tell(new TraceMsg());

        $runtime->scheduleOnce(Duration::millis(300), static fn() => $system->shutdown(Duration::seconds(1)));
        $system->run();
        $tracerProvider->forceFlush();

        $spans = $exporter->getSpans();
        self::assertGreaterThanOrEqual(2, count($spans));

        $byPath = [];

        foreach ($spans as $span) {
            $byPath[$span->getAttributes()->get('nexus.actor.path')] = $span;
        }

        self::assertArrayHasKey('/user/a', $byPath);
        self::assertArrayHasKey('/user/b', $byPath);
        // Same trace, and B is a child of A.
        self::assertSame($byPath['/user/a']->getTraceId(), $byPath['/user/b']->getTraceId());
        self::assertSame($byPath['/user/a']->getSpanId(), $byPath['/user/b']->getParentSpanId());
    }
}

final readonly class TraceMsg {}
