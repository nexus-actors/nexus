<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel;

use Http\Discovery\Psr17FactoryDiscovery;
use Monadial\Nexus\Observability\Config\ObservabilityConfig;
use Monadial\Nexus\Observability\Context\BaggagePropagator;
use Monadial\Nexus\Observability\Context\CompositePropagator;
use Monadial\Nexus\Observability\Context\TraceContextPropagator;
use Monadial\Nexus\Observability\NoopObservability;
use Monadial\Nexus\Observability\Observability;
use Monadial\Nexus\Observability\Otel\Export\ActorForwardingLogRecordExporter;
use Monadial\Nexus\Observability\Otel\Export\ActorForwardingMetricExporter;
use Monadial\Nexus\Observability\Otel\Export\ActorForwardingSpanExporter;
use Monadial\Nexus\Observability\Otel\Export\AsyncExportHandles;
use Monadial\Nexus\Observability\Otel\Http\SwooleCoroutinePsr18Client;
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

use function class_exists;
use function extension_loaded;

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
        $timeout = ($config->exporterTimeoutMillis ?? 10_000) / 1_000;
        $transports = self::transportFactory($timeout);
        $resource = ResourceInfoFactory::defaultResource()->merge(
            ResourceInfo::create(
                Attributes::create(['service.name' => $config->serviceName] + $config->resourceAttributes),
            ),
        );

        $spanExporter = new SpanExporter(
            $transports->create($endpoint . '/v1/traces', 'application/x-protobuf', timeout: $timeout),
        );
        $metricExporter = new MetricExporter(
            $transports->create($endpoint . '/v1/metrics', 'application/x-protobuf', timeout: $timeout),
            // Force cumulative temporality. Without an explicit choice the exporter's
            // temporality selector falls back to each metric's own metadata, which is
            // falsy for synchronous instruments — and ExportingReader::add() then skips
            // registering their source, so every counter/histogram is silently dropped
            // and only observable (async) instruments export. Cumulative is also what
            // Prometheus/Mimir expects.
            Temporality::CUMULATIVE,
        );
        $logsExporter = $config->logsEnabled
            ? new LogsExporter($transports->create($endpoint . '/v1/logs', 'application/x-protobuf', timeout: $timeout))
            : null;

        $forwardingSpans = null;
        $forwardingMetrics = null;
        $forwardingLogs = null;

        if ($config->asyncExport) {
            $forwardingSpans = new ActorForwardingSpanExporter($spanExporter);
            $forwardingMetrics = new ActorForwardingMetricExporter($metricExporter);
            $forwardingLogs = $logsExporter !== null
                ? new ActorForwardingLogRecordExporter($logsExporter)
                : null;
        }

        $tracerProvider = TracerProvider::builder()
            ->addSpanProcessor(
                new BatchSpanProcessor(
                    $forwardingSpans ?? $spanExporter,
                    Clock::getDefault(),
                ),
            )
            ->setResource($resource)
            ->setSampler(self::samplerFromConfig($config))
            ->build();

        $meterProvider = MeterProvider::builder()
            ->addReader(new ExportingReader($forwardingMetrics ?? $metricExporter))
            ->setResource($resource)
            ->build();

        $loggerProvider = null;

        if ($logsExporter !== null) {
            $loggerProvider = LoggerProvider::builder()
                ->setResource($resource)
                ->addLogRecordProcessor(
                    new BatchLogRecordProcessor(
                        $forwardingLogs ?? $logsExporter,
                        Clock::getDefault(),
                    ),
                )
                ->build();
        }

        $scope = $config->serviceName === ''
            ? 'nexus'
            : $config->serviceName;

        $asyncExportHandles = $config->asyncExport
            ? new AsyncExportHandles(
                spans: $forwardingSpans,
                metrics: $forwardingMetrics,
                logs: $forwardingLogs,
                innerSpans: $spanExporter,
                innerMetrics: $metricExporter,
                innerLogs: $logsExporter,
            )
            : null;

        return new OtelObservability(
            $tracerProvider,
            $meterProvider,
            new CompositePropagator([new TraceContextPropagator(), new BaggagePropagator()]),
            $scope,
            $loggerProvider,
            $asyncExportHandles,
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

    /**
     * OTLP/HTTP transport factory with a bounded, configurable deadline.
     *
     * Under Swoole, BOTH generic PHP HTTP client paths are broken inside coroutines
     * with SWOOLE_HOOK_ALL (verified empirically on Swoole 6.2):
     *
     *  1. the userland curl hook does not implement CURLOPT_SHARE, which symfony's
     *     curl client always sets — every export throws; and
     *  2. the hooked `http://` stream wrapper fails outright with
     *     "Failed to open stream: Failed to parse address" — every export dies after
     *     the retry limit, SILENTLY when SDK error logging is muted.
     *
     * So when ext-swoole is loaded the PSR-18 client is wrapped in
     * {@see SwooleCoroutinePsr18Client}: inside a coroutine it speaks
     * `Swoole\Coroutine\Http\Client` natively (no hooks involved, yields during I/O);
     * outside coroutines (boot, post-reactor shutdown flush) it delegates to the
     * stream client, which works unhooked. Without ext-swoole the stream client is
     * used directly. Falls back to the SDK's discovery when symfony/http-client is
     * not installed.
     */
    private static function transportFactory(float $timeoutSeconds): TransportFactoryInterface
    {
        if (class_exists(NativeHttpClient::class) && class_exists(Psr18Client::class)) {
            // Psr18Client also implements the PSR-17 request/stream factories.
            $psr = new Psr18Client(new NativeHttpClient([
                'max_duration' => $timeoutSeconds,
                'timeout' => $timeoutSeconds,
            ]));

            $client = extension_loaded('swoole')
                ? new SwooleCoroutinePsr18Client(
                    $psr,
                    Psr17FactoryDiscovery::findResponseFactory(),
                    Psr17FactoryDiscovery::findStreamFactory(),
                    $timeoutSeconds,
                )
                : $psr;

            return new PsrTransportFactory($client, $psr, $psr);
        }

        return new OtlpHttpTransportFactory();
    }
}
