<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel;

use Monadial\Nexus\Observability\Config\ObservabilityConfig;
use Monadial\Nexus\Observability\Context\BaggagePropagator;
use Monadial\Nexus\Observability\Context\CompositePropagator;
use Monadial\Nexus\Observability\Context\TraceContextPropagator;
use Monadial\Nexus\Observability\NoopObservability;
use Monadial\Nexus\Observability\Observability;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\Contrib\Otlp\MetricExporter;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler;
use OpenTelemetry\SDK\Trace\SamplerInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

/**
 * @psalm-api
 *
 * Builds a configured {@see Observability} provider from an
 * {@see ObservabilityConfig}. Returns {@see NoopObservability} when disabled.
 */
final class ObservabilityFactory
{
    public static function fromConfig(ObservabilityConfig $config): Observability
    {
        if (!$config->enabled) {
            return new NoopObservability();
        }

        $endpoint = $config->exporterEndpoint ?? 'http://localhost:4318';
        $resource = ResourceInfoFactory::defaultResource()->merge(
            ResourceInfo::create(
                Attributes::create(['service.name' => $config->serviceName] + $config->resourceAttributes),
            ),
        );

        $tracerProvider = TracerProvider::builder()
            ->addSpanProcessor(
                new BatchSpanProcessor(
                    new SpanExporter(
                        (new OtlpHttpTransportFactory())->create($endpoint . '/v1/traces', 'application/x-protobuf'),
                    ),
                    Clock::getDefault(),
                ),
            )
            ->setResource($resource)
            ->setSampler(self::samplerFromConfig($config))
            ->build();

        $meterProvider = MeterProvider::builder()
            ->addReader(
                new ExportingReader(
                    new MetricExporter(
                        (new OtlpHttpTransportFactory())->create($endpoint . '/v1/metrics', 'application/x-protobuf'),
                    ),
                ),
            )
            ->setResource($resource)
            ->build();

        $scope = $config->serviceName === ''
            ? 'nexus'
            : $config->serviceName;

        return new OtelObservability(
            $tracerProvider,
            $meterProvider,
            new CompositePropagator([new TraceContextPropagator(), new BaggagePropagator()]),
            $scope,
        );
    }

    public static function samplerFromConfig(ObservabilityConfig $config): SamplerInterface
    {
        $ratio = $config->samplerArg ?? 1.0;

        return match ($config->sampler) {
            'always_off' => new AlwaysOffSampler(),
            'always_on' => new AlwaysOnSampler(),
            'parentbased_always_off' => new ParentBased(new AlwaysOffSampler()),
            'parentbased_traceidratio' => new ParentBased(new TraceIdRatioBasedSampler($ratio)),
            'traceidratio' => new TraceIdRatioBasedSampler($ratio),
            default => new ParentBased(new AlwaysOnSampler()),
        };
    }
}
