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
use OpenTelemetry\Contrib\Otlp\LogsExporter;
use OpenTelemetry\Contrib\Otlp\MetricExporter;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Export\Http\PsrTransportFactory;
use OpenTelemetry\SDK\Common\Export\TransportFactoryInterface;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\Processor\BatchLogRecordProcessor;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
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
use Symfony\Component\HttpClient\NativeHttpClient;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * @psalm-api
 *
 * Builds a configured {@see Observability} provider from an
 * {@see ObservabilityConfig}. Returns {@see NoopObservability} when disabled.
 */
final class ObservabilityFactory
{
    /**
     * @psalm-suppress InvalidOperand int/float mixing computing the timeout in seconds is intentional.
     */
    public static function fromConfig(ObservabilityConfig $config): Observability
    {
        if (!$config->enabled) {
            return new NoopObservability();
        }

        $endpoint = $config->exporterEndpoint ?? 'http://localhost:4318';
        $timeout = ($config->exporterTimeoutMillis ?? 10_000) / 1_000.0;
        $transports = self::transportFactory($timeout);
        $resource = ResourceInfoFactory::defaultResource()->merge(
            ResourceInfo::create(
                Attributes::create(['service.name' => $config->serviceName] + $config->resourceAttributes),
            ),
        );

        $tracerProvider = TracerProvider::builder()
            ->addSpanProcessor(
                new BatchSpanProcessor(
                    new SpanExporter(
                        $transports->create($endpoint . '/v1/traces', 'application/x-protobuf', timeout: $timeout),
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
                        $transports->create($endpoint . '/v1/metrics', 'application/x-protobuf', timeout: $timeout),
                        // Force cumulative temporality. Without an explicit choice the exporter's
                        // temporality selector falls back to each metric's own metadata, which is
                        // falsy for synchronous instruments — and ExportingReader::add() then skips
                        // registering their source, so every counter/histogram is silently dropped
                        // and only observable (async) instruments export. Cumulative is also what
                        // Prometheus/Mimir expects.
                        Temporality::CUMULATIVE,
                    ),
                ),
            )
            ->setResource($resource)
            ->build();

        $loggerProvider = null;

        if ($config->logsEnabled) {
            $loggerProvider = LoggerProvider::builder()
                ->setResource($resource)
                ->addLogRecordProcessor(
                    new BatchLogRecordProcessor(
                        new LogsExporter(
                            $transports->create($endpoint . '/v1/logs', 'application/x-protobuf', timeout: $timeout),
                        ),
                        Clock::getDefault(),
                    ),
                )
                ->build();
        }

        $scope = $config->serviceName === ''
            ? 'nexus'
            : $config->serviceName;

        return new OtelObservability(
            $tracerProvider,
            $meterProvider,
            new CompositePropagator([new TraceContextPropagator(), new BaggagePropagator()]),
            $scope,
            $loggerProvider,
        );
    }

    /**
     * OTLP/HTTP transport factory with a bounded, configurable deadline.
     *
     * Prefers symfony's STREAM-based client (NativeHttpClient) over the discovered
     * default (CurlHttpClient) for two Swoole-critical reasons:
     *
     *  1. Swoole's userland curl hook (SWOOLE_HOOK_CURL, used when the extension is
     *     built without native curl support) does not implement CURLOPT_SHARE, which
     *     symfony's curl client always sets — every OTLP export then throws and no
     *     telemetry ever leaves the process.
     *  2. Under SWOOLE_HOOK_ALL the stream transport is coroutine-hooked, so exports
     *     yield to the scheduler instead of freezing the whole reactor for up to the
     *     full timeout per batch — a stalled collector otherwise starves gossip/timer
     *     processing (the OTel default timeout of 10 s equals common failure-detector
     *     windows).
     *
     * Falls back to the SDK's discovery when symfony/http-client is not installed.
     */
    private static function transportFactory(float $timeoutSeconds): TransportFactoryInterface
    {
        if (class_exists(NativeHttpClient::class) && class_exists(Psr18Client::class)) {
            // Psr18Client also implements the PSR-17 request/stream factories.
            $client = new Psr18Client(new NativeHttpClient([
                'max_duration' => $timeoutSeconds,
                'timeout' => $timeoutSeconds,
            ]));

            return new PsrTransportFactory($client, $client, $client);
        }

        return new OtlpHttpTransportFactory();
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
