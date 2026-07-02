<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel\Tests\Unit;

use Monadial\Nexus\Observability\Config\ObservabilityConfig;
use Monadial\Nexus\Observability\NoopObservability;
use Monadial\Nexus\Observability\Otel\ObservabilityFactory;
use Monadial\Nexus\Observability\Otel\OtelObservability;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ObservabilityFactory::class)]
#[CoversClass(OtelObservability::class)]
final class ObservabilityFactoryTest extends TestCase
{
    #[Test]
    public function disabledConfigYieldsNoopObservability(): void
    {
        self::assertInstanceOf(
            NoopObservability::class,
            ObservabilityFactory::fromConfig(ObservabilityConfig::disabled()),
        );
    }

    #[Test]
    public function enabledConfigYieldsOtelObservabilityWithWorkingPropagator(): void
    {
        $observability = ObservabilityFactory::fromConfig(
            ObservabilityConfig::enabled('orders')->withExporterEndpoint('http://localhost:4318'),
        );

        self::assertInstanceOf(OtelObservability::class, $observability);

        $context = $observability->propagator()->extract([
            'traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01',
        ]);
        self::assertSame('0af7651916cd43dd8448eb211c80319c', $context->spanContext->traceId);
    }

    #[Test]
    public function samplerMappingCoversEachMode(): void
    {
        self::assertInstanceOf(
            ParentBased::class,
            ObservabilityFactory::samplerFromConfig(ObservabilityConfig::enabled('a')),
        );
        self::assertInstanceOf(
            AlwaysOffSampler::class,
            ObservabilityFactory::samplerFromConfig(ObservabilityConfig::enabled('a')->withSampler('always_off', null)),
        );
        self::assertInstanceOf(
            TraceIdRatioBasedSampler::class,
            ObservabilityFactory::samplerFromConfig(
                ObservabilityConfig::enabled('a')->withSampler('traceidratio', 0.25),
            ),
        );
        self::assertInstanceOf(
            AlwaysOnSampler::class,
            ObservabilityFactory::samplerFromConfig(ObservabilityConfig::enabled('a')->withSampler('always_on', null)),
        );
        self::assertInstanceOf(
            ParentBased::class,
            ObservabilityFactory::samplerFromConfig(
                ObservabilityConfig::enabled('a')->withSampler('parentbased_always_off', null),
            ),
        );
        self::assertInstanceOf(
            ParentBased::class,
            ObservabilityFactory::samplerFromConfig(
                ObservabilityConfig::enabled('a')->withSampler('parentbased_traceidratio', 0.5),
            ),
        );
    }
}
